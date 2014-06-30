<?php 

	/*
	Plugin Name: Switch site to default theme when active theme is deleted
	Plugin URI: http://ctlt.ubc.ca/
	Description: On a multisite install, when a theme is deleted, any site using that theme gets a white screen of death until someone visits that site's Appearance > Themes page. That's not ideal.
	Author: CTLT Dev
	Author URI: http://ctlt.ubc.ca
	Version: 0.1
	*/

	if( !defined( 'ABSPATH' ) ){
		die( '-1' );
	}

	/*
	 * When a theme gets deleted, WordPress fires the 'delete_site_transient_update_themes' action before it deletes the transient.
	 * We're *should* be able to get the 'update_themes' transient (i.e. '_site_transient_update_themes' option in PREFIX_sitemeta) so 
	 * we have a record of which themes were available before deletion. Sadly, we can't, so we have to use the $_REQUEST.
	 * We set that value  to a class variable so that later, when the 
	 * 'deleted_site_transient' action fires we're able to get the result again so we can compare before and after to
	 * determine which theme/themes have been deleted.
	*/

	class CTLT_Switch_Site_To_Default_Theme_When_Active_Theme_Is_Deleted
	{

		// Used to store the update_themes transient before it is emptied when a theme is deleted
		var $transientValue = '';

		// Used to store which sites are running a theme which has just been deleted
		var $sitesRunningDeletedTheme = array();

		/**
		 * Our contstructor to set up our actions and filters when we're intialized
		 *
		 * @since 0.1
		 *
		 * @param null
		 * @return null
		 */

		public function __construct()
		{

			if( !is_multisite() ){
				return false;
			}

			add_action( 'delete_site_transient_update_themes', array( $this, 'delete_site_transient_update_themes__captureThemesBeforeDeletion' ), 1, 1 );
			add_action( 'deleted_site_transient', array( &$this, 'deleted_site_transient__captureThemesAfterDeltion' ), 999, 1 );

		}/* __construct() */


		/**
		 * Fetch the 'update_themes' transient before it is deleted (and rebuilt) so we have a record of which themes were active before 
		 * themes are deleted
		 *
		 * @since 0.1
		 *
		 * @param string $transient - the transient name
		 * @return null
		 */

		public function delete_site_transient_update_themes__captureThemesBeforeDeletion( $transient )
		{


			// Because of the way WP updates transients etc. we only really have access to what theme has been deleted
			// from the $_REQUEST/$_GET or $_POST variables
			$themeDeleted = ( $_REQUEST && is_array( $_REQUEST['checked'] ) && isset( $_REQUEST['checked'] ) ) ? $_REQUEST['checked'] : false;

			if( $themeDeleted == false ){
				$themeDeleted = ( $_POST && is_array( $_POST['checked'] ) && isset( $_POST['checked'] ) ) ? $_POST['checked'] : false;
			}

			// Now save this to a class variable
			$this->transientValue = $themeDeleted;

		}/* delete_site_transient_update_themes__captureThemesBeforeDeletion() */


		/**
		 * The deleted_site_transient action is run *after* the update_themes transient is deleted. We grab the value
		 * of the transient again and then determine which theme or themes have been deleted
		 *
		 * @since 0.1
		 *
		 * @param string $transient - the transient name
		 * @return null
		 */

		public function deleted_site_transient__captureThemesAfterDeltion( $transient )
		{

			if( $transient != 'update_themes' ){
				return;
			}

			// Now let's gran the value from before it was emptied
			$beforeDeleted = $this->transientValue;

			if( !$beforeDeleted || !is_array( $beforeDeleted ) || empty( $beforeDeleted ) ){
				return;
			}

			// Now we need to determine which sites in the network are running those themes
			foreach( $beforeDeleted as $key => $themeSlug )
			{
				
				// This will add all sites running this theme to the class var $sitesRunningDeletedTheme
				$this->determineSitesRunningTheme( $themeSlug );

			}
			
			// We need to go ahead and switch the sites running those themes
			// to the default theme
			$existing = get_site_option( 'sites_running_deleted_theme' );
			if( !$existing || !is_array( $existing ) || empty( $existing ) ){
				return;
			}

			foreach( $existing as $key => $siteID )
			{
				
				$this->switchSiteToDefaultTheme( $siteID );

			}

			// Now we need to clear that option
			delete_site_option( 'sites_running_deleted_theme' );

		}/* deleted_site_transient__captureThemesAfterDeltion() */


		/**
		 * Search through the database for sites running a particular theme and add to $this->sitesRunningDeletedTheme
		 * Each site, in the wp_#_options table has 'template' and 'stylesheet' options which is set to the slug of
		 * the theme name (i.e. 'twentyfourteen'), so there's poissibly a more efficient way to find this data than using
		 * switch_to_blog.
		 *
		 * @since 0.1
		 *
		 * @param string $themeSlug the theme slug for which to search
		 * @return null
		 */

		public static function determineSitesRunningTheme( $themeSlug = false )
		{

			// Run an action so we can hook in from elsewhere and overwrite what this method does
			do_action( 'sstdtwacid_before_determine_sites', $themeSlug );

			// We may have an alternative way to determine if there's a site running a theme
			// This might be especially useful on a large install
			$sitesRunningThisTheme = apply_filters( 'sstdtwacid_sites_running_' . $themeSlug, array(), $themeSlug );

			if( is_array( $sitesRunningThisTheme ) && !empty( $sitesRunningThisTheme ) )
			{

				// Looks like we have some results from the filter, so let's add themß
				foreach( $sitesRunningThisTheme as $key => $siteID )
				{
					
					$this->addSiteToSitesRunningDeletedTheme( $siteID );

				}

			}
			else
			{

				// OK, nothing from the filter, so we have to determine sites running this theme
				// This is a pretty heavy db call on large sites
				global $wpdb;

				$allSitesArgs = array(
					'network_id' => $wpdb->siteid,
					'public'     => 1,
					'limit'      => false
				);

				$allSitesArgs = apply_filters( 'sstdtwacid_get_site_args', $allSitesArgs, $themeSlug );

				$allSites = wp_get_sites( $allSitesArgs );

				if( !$allSites || !is_array( $allSites ) || empty( $allSites ) ){
					return;
				}

				foreach( $allSites as $key => $siteDetails )
				{
					
					$siteID = $siteDetails['blog_id'];

					switch_to_blog( $siteID );

					$thisSitesCurrentTheme = get_option( 'stylesheet' );

					if( $themeSlug == $thisSitesCurrentTheme )
					{

						// switch_to_blog()å means we're out of object context, so need to call this statically
						static::addSiteToSitesRunningDeletedTheme( $siteID );

					}

					restore_current_blog();

				}

			}

		}/* determineSitesRunningTheme() */


		/**
		 * Method to switch a specific site to the default theme (which is from the WP_DEFAULT_THEME)
		 *
		 * @since 0.1
		 *
		 * @param int $siteID the ID of the site we wish to switch to the default theme
		 * @return null
		 */

		public static function switchSiteToDefaultTheme( $siteID = false )
		{

			static::switchSiteToTheme( $siteID, WP_DEFAULT_THEME );

		}/* switchSiteToDefaultTheme() */


		/**
		 * Switches a site to a specific theme
		 *
		 * @since 0.1
		 *
		 * @param int $siteID The siteID for which we wish to switch themes
		 * @param string $themeSlug The slug of the theme to which we wish to change $siteID
		 * @return null
		 */

		public static function switchSiteToTheme( $siteID = false, $themeSlug = false )
		{

			if( !$siteID || ( absint( $siteID ) == 0 ) ){
				return new WP_Error( '1', 'switchSiteToTheme() requires a valid site ID' );
			}

			$themeSlug = sanitize_title( $themeSlug );

			switch_to_blog( $siteID );

			// The switch_theme function only provides a 'switch_theme' action at the end which only sends the name and theme. Run
			// a custom action before and after so we can hook in and know exactly which site it is that's changing (and therefore)
			// send emails or similar
			do_action( 'sstdtwacid_before_switching_theme', $siteID, $themeSlug );

			switch_theme( $themeSlug );

			do_action( 'sstdtwacid_after_switching_theme', $siteID, $themeSlug );

			restore_current_blog();

		}/* switchSiteToTheme() */


		/**
		 * Internal method to add a site ID to the list of site IDs using a deleted theme.
		 * Does basic checks to see if it's already been added
		 *
		 * @since 0.1
		 *
		 * @param int $siteID The site ID to add to the option
		 * @return null
		 */

		public static function addSiteToSitesRunningDeletedTheme( $siteID = false )
		{

			$existing = get_site_option( 'sites_running_deleted_theme' );

			if( !is_array( $existing ) || ( is_array( $existing ) && !in_array( $siteID, array_values( $existing ) ) ) )
			{

				$existing[] = $siteID;

				update_site_option( 'sites_running_deleted_theme', $existing );

			}

		}/* addSiteToSitesRunningDeletedTheme() */

	}/* class CTLT_Switch_Site_To_Default_Theme_When_Active_Theme_Is_Deleted */

	$CTLT_Switch_Site_To_Default_Theme_When_Active_Theme_Is_Deleted = new CTLT_Switch_Site_To_Default_Theme_When_Active_Theme_Is_Deleted();