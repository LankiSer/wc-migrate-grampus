(function($){
  $(function(){
    $('.wcm-tab').on('click', function(e){
      e.preventDefault();
      var target = $(this).attr('href');
      $('.wcm-tab').removeClass('is-active');
      $(this).addClass('is-active');
      $('.wcm-panel').removeClass('is-active');
      $(target).addClass('is-active');
    });
  });
})(jQuery);

