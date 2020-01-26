(function ($) {
    "use strict";
    if (Themify.is_builder_active || document.querySelector('form.builder-contact')!==null) {
        var send_form = function (form) {
            var data = new FormData($(form)[0]); //+
            data.append("action", "builder_contact_send");//+
            data.append("contact-settings", $('.builder-contact-form-data', form).html());//+
            if (form.find('[name="g-recaptcha-response"]').length > 0) {
                data.append("contact-recaptcha", form.find('[name="g-recaptcha-response"]').val());//+
            }
            $.ajax({
                url: form.prop('action'),
                method: 'POST',
                enctype: 'multipart/form-data', //+
                processData: false, //+
                contentType: false, //+
                cache: false, //+
                data: data,
                success: function (data) {
                    data = $.parseJSON(data);
                    if (data && data.themify_message) {
                        form.find('.contact-message').html(data.themify_message).fadeIn();
                        form.removeClass('sending');
                        $('html').stop().animate({scrollTop: form.offset().top - 100}, 500, 'swing');
                        if (data.themify_success) {
                            Themify.body.trigger('builder_contact_message_sent', [form, data.themify_message]);
                            form[0].reset();
                        } else {
                            Themify.body.trigger('builder_contact_message_failed', [form, data.themify_message]);
                        }
                        if (typeof grecaptcha === 'object') {
                            grecaptcha.reset();
                        }
                    }
                }
            });
        },

        callback = function () {
            if (!Themify.is_builder_active) {
                Themify.body.on('submit', '.builder-contact', function (e) {
                    e.preventDefault();
                    if ($(this).hasClass('sending')) {
                        return false;
                    }
                    $(this).addClass('sending').find('.contact-message').fadeOut();
                    send_form($(this));
                });
            }
        },
        captcha = function (el) {
            var cp = $('.g-recaptcha', el);
            if (cp.length > 0) {
                if (typeof grecaptcha === 'undefined') {
                    if(cp.data('sitekey')){
                        var url = 'https://www.google.com/recaptcha/api.js';
                        url += 'v3' === cp.data('ver') ? '?render='+cp.data('sitekey') : '';
                        Themify.LoadAsync(url, callback, false, true, function () {
                            return typeof grecaptcha !== 'undefined';
                        });
                    }
                }
                else {
                    callback();
                }
            }
            else {
                callback();
            }
        };

        Themify.body.on('focus', '.module-contact.contact-animated-label input, .module-contact.contact-animated-label textarea', function () {
            var label = $("label[for='" + $(this).attr('id') + "']"); //.addClass( 'inside' );
            if (label.length === 0) {
                label = $(this).closest(".builder-contact-field").find("label");
            }
            label.css({'top': 0, 'left': 0});
        }).on('blur', '.module-contact.contact-animated-label input, .module-contact.contact-animated-label textarea', function () {
            if ($(this).val() == "") {
                var label = $("label[for='" + $(this).attr('id') + "']"); //.addClass( 'inside' );
                if (label.length == 0)
                    label = $(this).closest(".builder-contact-field").find("label");
                var inputEl = label.next('.control-input').find('input,textarea');
                if (inputEl.prop('tagName') === 'TEXTAREA') {
                    // Label displacement for textarea should be calculated with it's row count in mind
                    label.css('top', (label.outerHeight() / 2 + inputEl.outerHeight() / parseInt(inputEl.prop('rows'))) + 'px');
                } else {
                    label.css('top', (label.outerHeight() / 2 + inputEl.outerHeight() / 2) + 'px');
                }
                label.css('left', '10px');
            }
        }).on('change', '.builder-contact-field .control-input input[type="checkbox"]', function () {
            var $group = $(this).closest('.control-input').find('input[type="checkbox"]');
            $group.prop('required', true);
            if ($group.is(":checked")) {
                $group.prop('required', false);
            }
        }).on('reset', 'form.builder-contact', function () {
            $(this).find('.builder-contact-field .control-input input[type="checkbox"]').prop('required', true);
        });
        var isWorking=true,
            contact_load = function (el) {
                var animated_labels=function (el) {
                    var items = $('.builder-contact-fields',el);
                       items.each(function () {

                           var $this = $(this),
                               list = $this.children('div').get(),
                               fr = document.createDocumentFragment();

                           list.sort(function (a, b) {
                               var compA = $(a).attr('data-order') ? parseInt($(a).attr('data-order')) : $(a).index(),
                                   compB = $(b).attr('data-order') ? parseInt($(b).attr('data-order')) : $(b).index();
                               return (compA < compB) ? -1 : (compA > compB) ? 1 : 0;
                           });
                           for(var i=0,len=list.length;i<len;++i){
                               fr.appendChild(list[i]);
                           }
                           this.appendChild(fr);
                   });
                  items = $('.module-contact.contact-animated-label', el);
                   if (el && el.hasClass('contact-animated-label')) {
                       items = items.add(el);
                   }
                   if (items.length > 0) {
                       items.find('input,textarea').prop('placeholder', '').trigger('blur');
                       setTimeout(function () {
                           items.find('label').css({
                               'transition-property': 'top, left',
                               'transition-duration': '0.3s',
                               'visibility': 'visible'
                           });
                           isWorking=null;
                       }, 50);
                   }
                   else{
                       isWorking=null;
                   }
                };
                animated_labels(el);
                captcha(el);
        };
        if (Themify.is_builder_active) {
            if (Themify.is_builder_loaded) {
                contact_load();
            }
            else{
                isWorking=null;
            }
        } else {
            contact_load();
        }
        Themify.body.on('builder_load_module_partial', function(e,el,type){
            if(isWorking===null){
                contact_load(el);
            }
        });
    }
}(jQuery));
