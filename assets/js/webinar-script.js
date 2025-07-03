jQuery(document).ready(function($) {
    // Video modal functionality
    $('.open-recording').on('click', function(e) {
        e.preventDefault();
        
        var videoUrl = $(this).data('url');
        var modal = $('#stream-modal');
        
        if (modal.length === 0) {
            $('body').append('<div class="video-modal-container" id="stream-modal"><div class="video-modal-content"><span class="video-modal-close">&times;</span><video controls class="stream-video"><source src="" type="video/mp4"></video></div></div>');
            modal = $('#stream-modal');
        }
        
        modal.find('video source').attr('src', videoUrl);
        modal.find('video')[0].load();
        modal.show();
    });
    
    // Close modal
    $(document).on('click', '.video-modal-close, .video-modal-container', function(e) {
        if (e.target === this) {
            $('#stream-modal').hide();
            $('#stream-modal video')[0].pause();
        }
    });
    
    // Escape key to close modal
    $(document).keyup(function(e) {
        if (e.keyCode === 27) {
            $('#stream-modal').hide();
            $('#stream-modal video')[0].pause();
        }
    });
    
    // Download confirmation
    $('.download-btn').on('click', function(e) {
        var fileName = $(this).attr('href').split('/').pop();
        return confirm('Möchten Sie die Datei "' + fileName + '" herunterladen?');
    });
});
