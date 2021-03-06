/**
 * Radio Player Script
 */

var volume = 55,
    is_playing = false,
    player,
    $player;

$(function() {

    // Check webstorage for existing volume preference.
    if (store.enabled && store.get('player_volume') !== undefined)
        volume = store.get('player_volume', volume);

    $('.btn-audio').on('click', function(e) {
        e.preventDefault();
        handlePlayClick($(this).data('url'));
        return false;
    });

    // Create audio element.
    player = document.createElement('audio');
    $player = $(player);

    setVolume(volume/100);

    var can_play_mp3 = !!(player.canPlayType && player.canPlayType('audio/mpeg;').replace(/no/, ''));
    if (!can_play_mp3)
        console.error('This browser cannot play MP3 files directly.');

    // Handle events.
    $player.on('play', function(e) {

        is_playing = true;

        $('.jp-unmute').hide();
        $('#radio-player-controls').addClass('jp-state-playing');

        var volume_percent = Math.round($player.volume * 100);
        $('.jp-volume-bar-value').css('width', volume_percent+'%');

    });

    $player.on('ended', function(e) {

        $('#radio-player-controls').removeClass('jp-state-playing');

    });

    // Handle button clicks.

    $('.jp-pause').on('click', function(e) {
        stopAllPlayers();
    });

    $('.jp-mute').on('click', function(e) {
        player.volume = 0;
        $('.jp-unmute').show();
        $('.jp-mute').hide();
    });

    $('.jp-unmute').on('click', function(e) {
        setVolume(volume);
        $('.jp-unmute').hide();
        $('.jp-mute').show();
    });

    $('.jp-volume-bar').on('click', function(e) {

        var $bar = $(e.currentTarget),
            offset = $bar.offset(),
            x = e.pageX - offset.left,
            w = $bar.width(),
            y = $bar.height() - e.pageY + offset.top,
            h = $bar.height();

        setVolume(x/w);
    });

});

function setVolume(new_volume)
{
    console.log('New volume: '+new_volume);

    volume = new_volume;

    var volume_percent = Math.round(volume * 100);
    $('.jp-volume-bar-value').css('width', volume_percent+'%');

    var v = Math.pow(volume,3);
    player.volume = v;

    if (store.enabled)
        store.set('player_volume', volume*100);
}

function handlePlayClick(audio_source)
{
    btn = $('.btn-audio[data-url="'+audio_source+'"]');

    if (btn.hasClass('playing')) {
        stopAllPlayers();
    } else {
        if (is_playing)
            stopAllPlayers();

        playAudio(audio_source);

        btn.addClass('playing').find('i').removeClass('zmdi-play').addClass('zmdi-stop');
    }
}

function playAudio(source_url)
{
    player.src = source_url;
    player.play();
}

function stopAllPlayers()
{
    player.pause();
    player.src = '';

    is_playing = false;
    $('.btn-audio').removeClass('playing').find('i').removeClass('zmdi-stop').addClass('zmdi-play');

    $('#radio-player-controls').removeClass('jp-state-playing');
}