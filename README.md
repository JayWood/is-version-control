**Plugin Name:** Is Version Controlled   
**Plugin URI:** http://www.plugish.com/      
**Author:** Jerry Wood Jr.   
**Version:** 0.1.0   
**Author URI:**	http://plugish.com      

This is a WordPress plugin designed for developers and agencies that just hate those calls of "Oops I updated the plugin" which we all know too well.
The intent behind this plugin is to block those updates to themes/plugins, and update notifications from within the WordPress admin.

## Filters

I tried to make this as easy as possible from a developer standpoint, so I've included some filters that fill the basic needs.  If you don't see something that you think should be here
then by all means make a pull request.

### ivc_plugins
Use this filter to remove plugins. Notice that plugins require `<folder>/<file>.php`

```php
function test_plugins( $plugins ) {
	$plugins[] = 'akismet/akismet.php';
	return $plugins;
}
add_filter( 'ivc_plugins', 'test_plugins' );
```

### ivc_themes
You can use this filter to remove specific themes from update checks.  Notice this only requires the theme slug ( usually the folder name ).

```php
function test( $themes ) {
	$themes[] = 'twentyfifteen';
	return $themes;
}
//add_filter( 'ivc_themes', 'test' );
```

### ivc_message_string
If you would like a different message string instead of the default on the plugins list page, you can use this.

```php
/**
 * Overwrites the default message for Version Control
 * @param string $message The Default Message
 * @param string $plugin_file The filepath to the plugin, ie. akismet/akismet.php
 * @param array $plugin_data Plugin data array, such as version, name, etc....
 */
function overwrite_message( $message, $plugin_file, $plugin_data ) {
	return 'Do not touch my plugin!';
}
add_filter( 'ivc_message_string', 'overwrite_message', 10, 3 );
```