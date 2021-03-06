<?php
namespace AzuraCast\Radio\Frontend;

use App\Utilities;
use Doctrine\ORM\EntityManager;
use Entity\StationMount;

class IceCast extends FrontendAbstract
{
    /* Process a nowplaying record. */
    protected function _getNowPlaying(&$np)
    {
        $fe_config = (array)$this->station->frontend_config;
        $radio_port = $fe_config['port'];

        $np_url = 'http://localhost:' . $radio_port . '/status-json.xsl';

        \App\Debug::log($np_url);

        $return_raw = $this->getUrl($np_url);

        if (!$return_raw) {
            return false;
        }

        $return = @json_decode($return_raw, true);

        \App\Debug::print_r($return);

        if (!$return || !isset($return['icestats']['source'])) {
            return false;
        }

        $sources = $return['icestats']['source'];

        if (empty($sources)) {
            return false;
        }

        if (key($sources) === 0) {
            $mounts = $sources;
        } else {
            $mounts = [$sources];
        }

        if (count($mounts) == 0) {
            return false;
        }

        $mounts = array_filter($mounts, function ($mount) {
            return (!empty($mount['title']) || !empty($mount['artist']));
        });

        // Sort in descending order of listeners.
        usort($mounts, function ($a, $b) {
            $a_list = (int)$a['listeners'];
            $b_list = (int)$b['listeners'];

            if ($a_list == $b_list) {
                return 0;
            } else {
                return ($a_list > $b_list) ? -1 : 1;
            }
        });

        $temp_array = $mounts[0];

        if (isset($temp_array['artist'])) {
            $np['current_song'] = [
                'artist' => $temp_array['artist'],
                'title' => $temp_array['title'],
                'text' => $temp_array['artist'] . ' - ' . $temp_array['title'],
            ];
        } else {
            $np['current_song'] = $this->getSongFromString($temp_array['title'], ' - ');
        }

        $np['meta']['status'] = 'online';
        $np['meta']['bitrate'] = $temp_array['bitrate'];
        $np['meta']['format'] = $temp_array['server_type'];

        $np['listeners']['current'] = (int)$temp_array['listeners'];

        return true;
    }

    public function read()
    {
        $config = $this->_getConfig();

        $this->station->frontend_config = $this->_loadFromConfig($config);

        return true;
    }

    public function write()
    {
        $config = $this->_getDefaults();

        $frontend_config = (array)$this->station->frontend_config;

        if (!empty($frontend_config['port'])) {
            $config['listen-socket']['port'] = $frontend_config['port'];
        }

        if (!empty($frontend_config['source_pw'])) {
            $config['authentication']['source-password'] = $frontend_config['source_pw'];
        }

        if (!empty($frontend_config['admin_pw'])) {
            $config['authentication']['admin-password'] = $frontend_config['admin_pw'];
        }

        if (!empty($frontend_config['streamer_pw'])) {
            foreach ($config['mount'] as &$mount) {
                if (!empty($mount['password'])) {
                    $mount['password'] = $frontend_config['streamer_pw'];
                }
            }
        }

        if (!empty($frontend_config['custom_config'])) {
            $custom_conf = $this->_processCustomConfig($frontend_config['custom_config']);
            if (!empty($custom_conf)) {
                $config = \App\Utilities::array_merge_recursive_distinct($config, $custom_conf);
            }
        }

        // Set any unset values back to the DB config.
        $this->station->frontend_config = $this->_loadFromConfig($config);

        $em = $this->di['em'];
        $em->persist($this->station);
        $em->flush();

        $config_path = $this->station->getRadioConfigDir();
        $icecast_path = $config_path . '/icecast.xml';

        $writer = new \App\Xml\Writer;
        $icecast_config_str = $writer->toString($config, 'icecast');

        // Strip the first line (the XML charset)
        $icecast_config_str = substr($icecast_config_str, strpos($icecast_config_str, "\n") + 1);

        file_put_contents($icecast_path, $icecast_config_str);
    }

    /*
     * Process Management
     */

    public function getCommand()
    {
        $config_path = $this->station->getRadioConfigDir() . '/icecast.xml';

        return 'icecast2 -c ' . $config_path;
    }

    public function getStreamUrl()
    {
        /** @var EntityManager */
        $em = $this->di->get('em');

        $mount_repo = $em->getRepository(StationMount::class);
        $default_mount = $mount_repo->getDefaultMount($this->station);

        $mount_name = ($default_mount instanceof StationMount) ? $default_mount->name : '/radio.mp3';

        return $this->getUrlForMount($mount_name);
    }

    public function getStreamUrls()
    {
        $urls = [];
        foreach ($this->station->mounts as $mount) {
            $urls[] = $this->getUrlForMount($mount->name);
        }

        return $urls;
    }

    public function getUrlForMount($mount_name)
    {
        return $this->getPublicUrl() . $mount_name . '?' . time();
    }

    public function getAdminUrl()
    {
        return $this->getPublicUrl() . '/admin/';
    }

    /*
     * Configuration
     */

    protected function _getConfig()
    {
        $config_path = $this->station->getRadioConfigDir();
        $icecast_path = $config_path . '/icecast.xml';

        $defaults = $this->_getDefaults();

        if (file_exists($icecast_path)) {
            $reader = new \App\Xml\Reader;
            $data = $reader->fromFile($icecast_path);

            return Utilities::array_merge_recursive_distinct($defaults, $data);
        }

        return $defaults;
    }

    protected function _loadFromConfig($config)
    {
        $frontend_config = (array)$this->station->frontend_config;

        return [
            'custom_config' => $frontend_config['custom_config'],
            'port' => $config['listen-socket']['port'],
            'source_pw' => $config['authentication']['source-password'],
            'admin_pw' => $config['authentication']['admin-password'],
            'streamer_pw' => $config['mount'][0]['password'],
        ];
    }

    protected function _getDefaults()
    {
        $config_dir = $this->station->getRadioConfigDir();

        $defaults = [
            'location' => 'AzuraCast',
            'admin' => 'icemaster@localhost',
            'hostname' => $this->di['em']->getRepository('Entity\Settings')->getSetting('base_url', 'localhost'),
            'limits' => [
                'clients' => 100,
                'sources' => 3,
                'threadpool' => 5,
                'queue-size' => 524288,
                'client-timeout' => 30,
                'header-timeout' => 15,
                'source-timeout' => 10,
                'burst-on-connect' => 1,
                'burst-size' => 65535,
            ],
            'authentication' => [
                'source-password' => Utilities::generatePassword(),
                'relay-password' => Utilities::generatePassword(),
                'admin-user' => 'admin',
                'admin-password' => Utilities::generatePassword(),
            ],

            'listen-socket' => [
                'port' => $this->_getRadioPort(),
            ],

            'mount' => [],
            'fileserve' => 1,
            'paths' => [
                'basedir' => '/usr/share/icecast2',
                'logdir' => $config_dir,
                'webroot' => '/usr/share/icecast2/web',
                'adminroot' => '/usr/share/icecast2/admin',
                'pidfile' => $config_dir . '/icecast.pid',
                'alias' => [
                    '@source' => '/',
                    '@destination' => '/status.xsl',
                ],
            ],
            'logging' => [
                'accesslog' => 'icecast_access.log',
                'errorlog' => 'icecast_error.log',
                'loglevel' => 3,
                'logsize' => 10000,
            ],
            'security' => [
                'chroot' => 0,
            ],
        ];

        $url = $this->di['url'];

        foreach ($this->station->mounts as $mount_row) {
            $mount = [
                '@type' => 'normal',
                'mount-name' => $mount_row->name,
            ];

            if (!empty($mount_row->fallback_mount)) {
                $mount['fallback-mount'] = $mount_row->fallback_mount;
                $mount['fallback-override'] = 1;
            }

            if ($mount_row->enable_streamers) {
                $mount['username'] = 'shoutcast';
                $mount['password'] = Utilities::generatePassword();
                $mount['authentication'] = [
                    '@type' => 'url',
                    'option' => [
                        [
                            '@name' => 'stream_auth',
                            '@value' => $url->route([
                                'module' => 'api',
                                'controller' => 'internal',
                                'action' => 'streamauth',
                                'id' => $this->station->id
                            ], true)
                        ],
                    ],
                ];

                $defaults['listen-socket']['shoutcast-mount'] = $mount_row->name;
            }

            if ($mount_row->frontend_config) {
                $mount_conf = $this->_processCustomConfig($mount_row->frontend_config);
                if (!empty($mount_conf)) {
                    $mount = \App\Utilities::array_merge_recursive_distinct($mount, $mount_conf);
                }
            }

            $defaults['mount'][] = $mount;
        }

        return $defaults;
    }

    public function getDefaultMounts()
    {
        return [
            [
                'name' => '/radio.mp3',
                'is_default' => 1,
                'fallback_mount' => '/autodj.mp3',
                'enable_streamers' => 1,
                'enable_autodj' => 0,
            ],
            [
                'name' => '/autodj.mp3',
                'is_default' => 0,
                'fallback_mount' => '/error.mp3',
                'enable_streamers' => 0,
                'enable_autodj' => 1,
                'autodj_format' => 'mp3',
                'autodj_bitrate' => 128,
            ]
        ];
    }
}