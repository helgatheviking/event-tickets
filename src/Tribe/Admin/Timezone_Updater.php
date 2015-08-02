<?php
/**
 * Tool for adding timezone data to events.
 *
 * The application for this is in transitioning any event data created in 3.11.x or
 * earlier that hasn't since been updated, so that it becomes "timezone ready".
 */
class Tribe__Events__Admin__Timezone_Updater {
	protected $initial_count = 0;

	public function init_update() {
		if ( $this->update_needed() ) {
			$this->initial_count = $this->count_ids();
			$batch_size = (int) apply_filters( 'tribe_events_timezone_updater_batch_size', 50 );
			$this->process( $batch_size );
		}

		$this->notice_setup();
	}

	protected function notice_setup() {
		add_action( 'admin_notices', array( $this, 'notice_display' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'notice_assets' ) );
	}

	public function notice_display() {
		$update = $this->notice_inner();
		echo '<div class="tribe-events-timezone-update-msg updated updating"> ' . $update . ' </div>';
	}

	public function notice_inner() {
		$remaining = $this->count_ids();
		$spinner   = ' <img src="' . get_admin_url( null, '/images/spinner.gif' ) . '">';

		$progress = ( 0 < $remaining )
			? $this->calculate_progress( $remaining )
			: 100;

		$update = $remaining
			? __( 'Please wait while timezone data is added to your events.', 'tribe-events-calendar' )
			: __( 'Update complete: timezone data has been added to all events in the database.', 'tribe-events-calendar' );

		$update = "<p> $update </p>";

		if ( 100 === $progress ) {
			$spinner = '';
		}

		if ( $progress >= 0 ) {
			$percent = sprintf( __( '%d%% complete', 'tribe-events-calendar' ), $progress );
			$update .= '<div class="tribe-update-bar"> <div class="progress" title="' . $percent . '"> <div class="bar" style="width: ' . $progress . '%"></div> </div>' . $spinner . '</div>';
		}

		return $update;
	}

	public function notice_assets() {
		$plugin = Tribe__Events__Main::instance();
		$script = trailingslashit( $plugin->pluginUrl ) . 'src/resources/js/events-admin-timezone-updater.js';
		$handle = 'tribe-events-ajax-timezone-update';

		wp_enqueue_script( $handle, $script, array( 'jquery' ), false, true );
		wp_localize_script( $handle, 'tribe_timezone_update', array(
			'continue' => $this->update_needed(),
			'failure_msg' => __( 'A problem stopped the timezone update process from completing. Please refresh and try again.', 'tribe-events-calendar' ),
			'check' => wp_create_nonce( 'timezone-settings' ),
		) );
	}

	/**
	 * Returns an integer representing the degree to which the update task has progressed
	 * as a percentage of events in need of updating.
	 *
	 * @param  int $remaining
	 *
	 * @return int
	 */
	protected function calculate_progress( $remaining ) {
		if ( $this->initial_count ) {
			$percentage = ( $this->initial_count - $remaining ) / $this->initial_count;
			return (int) ( $percentage * 100 );
		}

		return 0;
	}

	/**
	 * Updates the next batch of non-timezone ready events.
	 *
	 * @param int $batch_size (defaults to -1 meaning "update all")
	 */
	public function process( $batch_size = -1 ) {
		$site_timezone = Tribe__Events__Timezones::wp_timezone_string();

		foreach ( $this->get_ids( $batch_size ) as $event_id ) {
			$local_start_time = tribe_get_start_date( $event_id, true, Tribe__Events__Date_Utils::DBDATETIMEFORMAT );
			$utc_start_time = Tribe__Events__Timezones::to_utc( $local_start_time, $site_timezone );

			$local_end_time = tribe_get_end_date( $event_id, true, Tribe__Events__Date_Utils::DBDATETIMEFORMAT );
			$utc_end_time = Tribe__Events__Timezones::to_utc( $local_end_time, $site_timezone );

			update_post_meta( $event_id, '_EventTimezone', $site_timezone );
			update_post_meta( $event_id, '_EventStartDateUTC', $utc_start_time );
			update_post_meta( $event_id, '_EventEndDateUTC', $utc_end_time );
		}
	}

	/**
	 * Return an array of event IDs for those events that still do not have
	 * timezone data.
	 *
	 * @param  int $limit
	 *
	 * @return array
	 */
	public function get_ids( $limit = -1 ) {
		return $this->find( $limit );
	}

	/**
	 * Get the number of events that still require timezone data.
	 *
	 * @return int
	 */
	public function count_ids() {
		return $this->find( -1, true );
	}

	/**
	 * Indicates if there are still events that need to be updated
	 * with timezone data.
	 *
	 * @return bool
	 */
	public function update_needed() {
		return (bool) $this->find( 1, true );
	}

	/**
	 * Utility function that can return either an array of IDs for all (or the specified
	 * number) of events without timezone data, or alternatively can return a count of
	 * those events.
	 *
	 * @param  int        $limit
	 * @param  bool|false $count
	 *
	 * @return array|int
	 */
	protected function find( $limit = -1, $count = false ) {
		global $wpdb;

		// Form the limit clause if needed
		$limit = ( (int) $limit > 0 )
			? 'LIMIT ' . absint( $limit )
			: '';

		// Are we making a count or obtaining the actual IDs?
		$requested_data = $count
			? 'COUNT( DISTINCT( ID ) )'
			: 'DISTINCT( ID )';

		$query = "
			-- Look for events not returned by the inner query
			SELECT $requested_data
			FROM   $wpdb->posts
			WHERE  $wpdb->posts.post_type = %s
			AND    ID NOT IN (
			           -- Find those posts that already have timezone meta data
			           SELECT DISTINCT ( post_id )
			           FROM   $wpdb->postmeta
			           WHERE  meta_key = '_EventTimezone'
			       )
			$limit;
		";

		$prepared_query = $wpdb->prepare( $query, Tribe__Events__Main::POSTTYPE );

		return $count
			? (int) $wpdb->get_var( $prepared_query )
			: (array) $wpdb->get_col( $prepared_query );
	}
}