<?php

if ( ! defined('THEMIFY_UPDATER_MENU_PAGE') ) die();

$current_theme = wp_get_theme();

?>

<div class="promote-themes" style="display:none;">
	<div class="container"></div>
</div>
<div class="promote-plugins" style="display:none;">
	<ul class="plugin-category">
		<li class="active" data-type="promo-plugins"><a href="#">Plugins</a></li>
		<li data-type="promo-builder-addons"><a href="#">Builder Addons</a></li>
		<li data-type="promo-ptb-addons"><a href="#">PTB Addons</a></li>
	</ul>
	<div class="container"></div>
</div>

<script type="text/html" id="tmpl-themify-featured-theme-item">
<ol class="grid3 theme-list clearfix">
    <# var extra = data.extra, themify_promotion = window.themify_promotion; #>
	<# jQuery.each( data, function( i, e ) { #>
		<li class="theme-post">
			<figure class="theme-image">
				<a href="{{{e.url}}}" target="_blank">
                    <img src="https://themify.me/wp-content/product-img/{{{e.slug}}}-thumb.jpg" alt="{{{e.title}}}">
				</a>
			</figure>
			<div class="theme-info">
				<div class="theme-title">
					<h3><a href="{{{e.url}}}" target="_blank">{{{e.title}}}</a></h3>
				    	<a class="tag-button lightbox" target="_blank" href="https://themify.me/demo/#theme={{{e.slug}}}"><?php _e( 'demo', 'themify-updater' ); ?></a>
                </div>
				<!-- /theme-title -->
				<div class="theme-excerpt">
					<p>{{{e.description}}}</p>
                    <#
                    if ( typeof themify_promotion !== 'undefined' && !jQuery.isEmptyObject( themify_promotion )) {
                    #>
                    <# for ( promotion in themify_promotion['install'] ) {
							if ( themify_promotion['install'][promotion]['promo'] == e.slug ){ #>
                        <a class="install-button lightbox" href="#" onclick="themify_updater_install( event , '{{{themify_promotion['install'][promotion]['name']}}}' , '{{{extra.type}}}' , '{{{promotion}}}' )"><?php _e( 'Install', 'themify-updater' ); ?></a>
                    <#		temp=true;
							break;
							}
						}
					for ( promotion in themify_promotion['buy'] ) {
							if ( themify_promotion['buy'][promotion]['promo'] == e.slug ){ #>
                        <a class="install-button lightbox" href="{{{e.url}}}" target="_blank" ><?php _e( 'Buy', 'themify-updater' ); ?></a>
                    <#		temp=true;
							break;
							}
						}
					for ( promotion in themify_promotion['installed'] ) { 
							if ( themify_promotion['installed'][promotion]['promo'] == e.slug ){
                            var temp = themify_updater_previous_versions( themify_promotion['installed'][promotion]['version'] );
                    #>
                    <label class="themify-updater-dropdown">
                        <div class="themify-updater-dd-button"><?php _e( 'Re-install', 'themify-updater'); ?></div>
                        <input type="checkbox" class="themify-updater-dd-input" >
                        <ul class="themify-updater-dd-menu" onclick="themify_updater_previous_reinstall(event, '{{{themify_promotion['installed'][promotion]['name']}}}' , 'theme', '{{{promotion}}}' )">{{{ temp }}}</ul>
                    </label>
                    <#		temp=true;
							break;
							}
						}
					#>
                    <# } #>
                </div>
				<!-- /theme-excerpt -->
			</div>
			<!-- /theme-info -->	
		</li>
	<# } ) #>
</ol>
</script>

<script type="text/html" id="tmpl-themify-featured-plugin-item">
<ol class="grid3 theme-list clearfix">
    <# var extra = data.extra, demolink = 'https://themify.me/demo/themes/', themify_promotion = window.themify_promotion;
    jQuery.each( data, function( i, e ) {
		if (e.category === 'promo-builder-addons') {
			e.demolink =  demolink + 'addon-' + e.slug;
		} else if (e.category === 'promo-plugins') {
			e.demolink =  demolink + e.slug;
			switch (e.slug) {
				case 'shopify-buy-button': e.demolink = demolink + 'simple'; break;
				case 'themify-product-filter': e.demolink = demolink + 'wc-product-filter'; break;
				case 'post-type-builder': e.demolink = demolink + 'ptb-bundle'; break;
				case 'themify-icons': e.demolink = e.url; break;
				case 'event-post': e.demolink = demolink + 'events-post'; break;
			}
		} else {
			e.demolink =  demolink + 'ptb-addon-' + e.slug;
			switch (e.slug) {
				case 'relation': e.demolink = demolink + 'ptb-bundle/celebrity-relation/'; break;
				case 'map-view': e.demolink = demolink + 'ptb-bundle/map-view/'; break;
				case 'search': e.demolink = demolink + 'ptb-bundle/properties/'; break;
			}
		}
	#>
		<li class="theme-post {{{e.category}}}">
			<figure class="theme-image">
				<a href="{{{e.url}}}" target="_blank">
                    <img src="https://themify.me/wp-content/product-img/{{{ e.url.replace('https:\/\/themify.me\/', '') }}}.jpg" alt="{{{e.title}}}">
				</a>
			</figure>
			<div class="theme-info">
				<div class="theme-title">
					<h3><a href="{{{e.url}}}" target="_blank">{{{e.title}}}</a></h3>
				    	<a class="tag-button lightbox" target="_blank" href="{{{e.demolink}}}"><?php _e( 'demo', 'themify-updater' ); ?></a>
                </div>
				<!-- /theme-title -->
				<div class="theme-excerpt">
					<p>{{{e.description}}}</p>
                    <# if ( typeof themify_promotion !== 'undefined' && !jQuery.isEmptyObject( themify_promotion ) ) { #>
                    <# for ( promotion in themify_promotion['install'] ) {
							if ( themify_promotion['install'][promotion]['promo'] == e.slug ){ #>
                        <a class="install-button lightbox" href="#" onclick="themify_updater_install( event , '{{{themify_promotion['install'][promotion]['name'].replace('-plugin','')}}}' , '{{{extra.type}}}' , '{{{promotion}}}' )"><?php _e( 'Install', 'themify-updater' ); ?></a>
                    <#		temp=true;
							break;
							}
						}
					for ( promotion in themify_promotion['buy'] ) { 
							if ( themify_promotion['buy'][promotion]['promo'] == e.slug ){ #>
                        <a class="install-button lightbox" href="{{{e.url}}}" target="_blank" ><?php _e( 'Buy', 'themify-updater' ); ?></a>
                    <#		temp=true;
							break;
							}
						}
					for ( promotion in themify_promotion['installed'] ) { 
							if ( themify_promotion['installed'][promotion]['promo'] == e.slug ){
                            var temp = themify_updater_previous_versions( themify_promotion['installed'][promotion]['version'] );
                    #>
                    <label class="themify-updater-dropdown">
                        <div class="themify-updater-dd-button"><?php _e( 'Re-install', 'themify-updater'); ?></div>
                        <input type="checkbox" class="themify-updater-dd-input" >
                        <ul class="themify-updater-dd-menu" onclick="themify_updater_previous_reinstall(event, '{{{themify_promotion['installed'][promotion]['name']}}}' , 'plugin', '{{{promotion}}}' )">{{{ temp }}}</ul>
                    </label>
                    <#	temp=true;
							break;
							}
						}
					#>
                    <# } #>
                </div>
				<!-- /theme-excerpt -->
			</div>
			<!-- /theme-info -->	
		</li>
	<# } ) #>
</ol>
</script>

<script type="text/javascript">

	jQuery(function($) {
		
		
		var promo_data = false;
		var type = "<?php echo $this->type; ?>";
		var container = $('.promote-'+ type +'s .container');
		
		$(document).bind('themify_update_promo', function () {
			
			container.parent().show();

			if (!promo_data) {
				container.text('Loading...');
				$.getJSON( 'https://themify.me/public-api/featured-'+ type +'s/index.json' )
				.done(function( data ){
					data.currentThemeURI = "<?php echo $current_theme->display( 'ThemeURI' ); ?>";
					data.installLink = "<?php echo esc_url( wp_nonce_url( add_query_arg('install', '%themify_updater%'), 'install_product_' . $_GET['promotion'] ) ); ?>";
					data.extra = {'type': type};

					promo_data = data;
					$(document).trigger('themify_update_promo');
				}).fail(function( jqxhr, textStatus, error ){
					container.html( '<p><?php _e( 'Something went wrong while fetching the Featured Themes. Please try again later.', 'themify-updater' ); ?></p>' );
				});
				
				if (type == 'plugin') {
					$('.promote-plugins ul.plugin-category a').on('click', themify_plugin_change_cat);
				}
			}
			
			var template = wp.template( 'themify-featured-'+ type +'-item' );
			container.html( template( promo_data ) );

            $( "select.themify_updater_reinstall_select" ).select(function() {
                console.log( "Handler for .select() called." );
            });
			if (type == 'plugin') {
				$('.promote-plugins ul.plugin-category li.active a').click();
			}
			
		}).ready( function () {
			$(document).trigger('themify_update_promo');
		});
		
		function themify_plugin_change_cat (e) {

			e.preventDefault();
			e.stopPropagation();
			
			$th = $(e.target).parent();
			$th.addClass('active').siblings().removeClass('active');
			$item = $('.theme-post.'+ $th.data('type')).show();
			$siblings = $item.siblings('li:not(.'+ $th.data('type') +')');

			$siblings.hide();
			
			$item.parent().append($siblings);
		}
	
	}(jQuery));
	
	function themify_updater_install (e , name, product_type, nonce, type, version ) {
		if (e) e.preventDefault();

		if (!confirm(themify_upgrader.installation_message)) return;

		if ( typeof version === 'undefined' || version.toLowerCase() === 'latest version') version = '';

		adminLink = "<?php echo network_admin_url( 'update.php' ); ?>";
		if ( typeof type !== 'undefined' && type == 'upgrade') {
			document.location = adminLink + "?action=upgrade-" + product_type + "&" + product_type + "=" + name + "&_wpnonce=" + nonce + "&themify_theme_downgrade=1&version=" + version;
		} else {
			document.location = adminLink + "?action=install-" + product_type + "&" + product_type + "=" + name + "&_wpnonce=" + nonce;
		}
	}

	function themify_updater_previous_reinstall (e, name, product_type, nonce) {
        version = e.target.innerHTML;
        themify_updater_install(null, name, product_type, nonce, 'upgrade', version);
    }

    function themify_updater_previous_versions( latest_version, $back_limit) {
        let html = "<li>" + window.themify_updater_promo['text']['latest_version'] + "</li>";
        let i = 0, versions = new Array(), last_version = '1.0';

        if ( typeof $back_limit === 'undefined') $back_limit = 3;
        if ( latest_version < last_version) last_version = '0.0.1';

        while ( i < $back_limit ) {
            if ( i === 0 && latest_version > last_version) {
                versions[i] = themify_updater_previous_version( latest_version );
            }
            else if ( versions[i-1] !== '' && versions[i-1] > last_version ) {
                versions[i] = themify_updater_previous_version( versions[i-1] );
            } else {
                break;
            }
            ++i;
        }

        for ( i = 0; i < versions.length ; i++) {
            html += "<li>"+ versions[i] + "</li>";
        }

        return html;
    }

    function themify_updater_previous_version ( version ) {
        let back_version = '';
        let parts = version.split('.');
        if ( parts.length === 3 ) {
            if ( parseInt(parts[2]) > 0 ) {
                parts[2]--;
            }
            else if ( parseInt(parts[1]) > 0 ) {
                parts[2] = '9';
                parts[1]--;
            }
            else if ( parseInt(parts[0]) > 1 ) {
                parts[2] = '9';
                parts[1] = '9';
                parts[0]--;
            }
            else {
                parts = false;
            }
        }
        if ( parts ) {
            back_version = parts.join('.');
        }
        return back_version;
    }
</script>