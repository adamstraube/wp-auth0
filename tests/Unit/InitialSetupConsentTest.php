<?php
/**
 * Contains Class TestInitialSetupConsent.
 *
 * @package WP-Auth0
 *
 * @since 3.8.1
 */

class InitialSetupConsentTest extends WP_Auth0_Test_Case {

	use HttpHelpers {
		httpMock as protected httpMockDefault;
	}

	use RedirectHelpers;

	/**
	 * Test that the create client call is made.
	 */
	public function testThatClientCreationIsAttempted() {
		$this->startHttpHalting();

		$setup_consent = new WP_Auth0_InitialSetup_Consent( self::$opts );
		$test_token    = implode( '.', [ uniqid(), uniqid(), uniqid() ] );

		$caught_http = [];
		try {
			$setup_consent->callback_with_token( 'test-wp.auth0.com', $test_token );
		} catch ( Exception $e ) {
			$caught_http = unserialize( $e->getMessage() );
		}

		// Just want to test if the Create Client call happened.
		// Unit testing for what exactly was sent should be written against WP_Auth0_Api_Client::create_client().
		$this->assertNotEmpty( $caught_http );
		$this->assertEquals( 'https://test-wp.auth0.com/api/v2/clients', $caught_http['url'] );
		$this->assertEquals( 'Bearer ' . $test_token, $caught_http['headers']['Authorization'] );

		$this->assertEmpty( self::$error_log->get() );
	}

	/**
	 * Test that the redirect when the create client call fails.
	 */
	public function testThatClientCreationFailureIsRedirected() {
		$this->startRedirectHalting();
		$this->startHttpMocking();

		$setup_consent = new WP_Auth0_InitialSetup_Consent( self::$opts );
		$test_token    = implode( '.', [ uniqid(), uniqid(), uniqid() ] );

		// Mock the create client call with a WP error.
		$this->http_request_type = 'wp_error';
		$caught_redirect         = [];
		try {
			$setup_consent->callback_with_token( 'test-wp.auth0.com', $test_token );
		} catch ( Exception $e ) {
			$caught_redirect = unserialize( $e->getMessage() );
		}

		$this->assertNotEmpty( $caught_redirect );
		$this->assertEquals( 302, $caught_redirect['status'] );

		$redirect_url = parse_url( $caught_redirect['location'] );

		$this->assertEquals( '/wp-admin/admin.php', $redirect_url['path'] );
		$this->assertContains( 'page=wpa0-setup', $redirect_url['query'] );
		$this->assertContains( 'error=cant_create_client', $redirect_url['query'] );

		$this->assertCount( 1, self::$error_log->get() );
	}

	/**
	 * Test that an existing DB connection is used instead of creating one.
	 */
	public function testThatExistingConnectionSkipsCreation() {
		$this->startHttpMocking();
		$this->startRedirectHalting();

		self::$opts->set( 'client_signing_algorithm', 'HS256' );

		$setup_consent = new WP_Auth0_InitialSetup_Consent( self::$opts );
		$test_token    = implode( '.', [ uniqid(), uniqid(), uniqid() ] );

		// Mock consecutive HTTP calls.
		$this->http_request_type = [
			// Successful client creation.
			'success_create_client',
			// Found a connection with the same name.
			'success_get_existing_conn',
			// Client grant created successfully.
			'success_create_empty_body',
		];

		$caught_redirect = [];
		try {
			$setup_consent->callback_with_token( 'test-wp.auth0.com', $test_token );
		} catch ( Exception $e ) {
			$caught_redirect = unserialize( $e->getMessage() );
		}

		$this->assertNotEmpty( $caught_redirect );
		$this->assertEquals( 302, $caught_redirect['status'] );

		$redirect_url = parse_url( $caught_redirect['location'] );

		$this->assertEquals( '/wp-admin/admin.php', $redirect_url['path'] );
		$this->assertContains( 'page=wpa0-setup', $redirect_url['query'] );
		$this->assertContains( 'step=2', $redirect_url['query'] );

		$this->assertEquals( 'TEST_CLIENT_ID', self::$opts->get( 'client_id' ) );
		$this->assertEquals( 'TEST_CLIENT_SECRET', self::$opts->get( 'client_secret' ) );

		$this->assertEmpty( self::$error_log->get() );
	}

	/**
	 * Test that an connection is created and the Client Grant fails.
	 */
	public function testThatNewConnectionIsCreatedAndFailedClientGrantRedirects() {
		$this->startHttpMocking();
		$this->startRedirectHalting();

		$setup_consent = new WP_Auth0_InitialSetup_Consent( self::$opts );
		$test_token    = implode( '.', [ uniqid(), uniqid(), uniqid() ] );

		self::$opts->set( 'client_signing_algorithm', 'HS256' );

		// Mock consecutive HTTP calls.
		$this->http_request_type = [
			// Successful client creation.
			'success_create_client',
			// Get an existing connection enabled for this client.
			'success_get_connections',
			// Client grant failed.
			'wp_error',
		];

		$caught_redirect = [];
		try {
			$setup_consent->callback_with_token( 'test-wp.auth0.com', $test_token );
		} catch ( Exception $e ) {
			$caught_redirect = unserialize( $e->getMessage() );
		}

		$this->assertNotEmpty( $caught_redirect );
		$this->assertEquals( 302, $caught_redirect['status'] );

		$redirect_url = parse_url( $caught_redirect['location'] );

		$this->assertEquals( '/wp-admin/admin.php', $redirect_url['path'] );
		$this->assertContains( 'page=wpa0-setup', $redirect_url['query'] );
		$this->assertContains( 'error=cant_create_client_grant', $redirect_url['query'] );

		$this->assertEquals( 'TEST_CLIENT_ID', self::$opts->get( 'client_id' ) );
		$this->assertEquals( 'TEST_CLIENT_SECRET', self::$opts->get( 'client_secret' ) );
		$this->assertEquals( 'DB-' . get_auth0_curatedBlogName(), self::$opts->get( 'db_connection_name' ) );

		$this->assertCount( 1, self::$error_log->get() );
	}

	/**
	 * Test that an connection is created and the Client Grant fails.
	 */
	public function testThatNewConnectionIsCreatedWithExistingMigrationToken() {
		$this->startHttpMocking();
		$this->startRedirectHalting();

		$setup_consent = new WP_Auth0_InitialSetup_Consent( self::$opts );
		$test_token    = implode( '.', [ uniqid(), uniqid(), uniqid() ] );

		self::$opts->set( 'client_signing_algorithm', 'HS256' );
		self::$opts->set( 'migration_token', 'TEST_MIGRATION_TOKEN' );

		// Mock consecutive HTTP calls.
		$this->http_request_type = [
			// Successful client creation.
			'success_create_client',
			// Get en existing connection enabled for this client.
			'success_get_connections',
			// Connection created successfully.
			'success_create_connection',
			// Client grant created successfully.
			'success_create_client_grant',
		];

		$caught_redirect = [];
		try {
			$setup_consent->callback_with_token( 'test-wp.auth0.com', $test_token );
		} catch ( Exception $e ) {
			$caught_redirect = unserialize( $e->getMessage() );
		}

		$this->assertNotEmpty( $caught_redirect );
		$this->assertEquals( 302, $caught_redirect['status'] );

		$redirect_url = parse_url( $caught_redirect['location'] );

		$this->assertEquals( '/wp-admin/admin.php', $redirect_url['path'] );
		$this->assertContains( 'page=wpa0-setup', $redirect_url['query'] );
		$this->assertContains( 'step=2', $redirect_url['query'] );

		$this->assertEquals( 'TEST_CLIENT_ID', self::$opts->get( 'client_id' ) );
		$this->assertEquals( 'TEST_CLIENT_SECRET', self::$opts->get( 'client_secret' ) );
		$this->assertEquals( 'TEST_MIGRATION_TOKEN', self::$opts->get( 'migration_token' ) );
		$this->assertEquals( 'DB-' . get_auth0_curatedBlogName(), self::$opts->get( 'db_connection_name' ) );

		$this->assertEmpty( self::$error_log->get() );
	}

	/*
	 * Test helper functions.
	 */

	/**
	 * Specific mock API responses for this suite.
	 *
	 * @return array|WP_Error
	 *
	 * @throws Exception If asked to do so.
	 */
	public function httpMock() {
		$response_type = $this->getResponseType();
		switch ( $response_type ) {
			case 'success_create_client':
				return [
					'body'     => '{"client_id":"TEST_CLIENT_ID","client_secret":"TEST_CLIENT_SECRET"}',
					'response' => [ 'code' => 201 ],
				];

			case 'success_get_existing_conn':
				return [
					'body'     => '[{"id":"TEST_CONN_ID","name":"DB-' . get_auth0_curatedBlogName() .
						'","enabled_clients":["TEST_CLIENT_ID"],"options":{"passwordPolicy":"good"}}]',
					'response' => [ 'code' => 200 ],
				];

			case 'success_create_client_grant':
				$audience = 'https://' . self::$opts->get( 'domain' ) . '/api/v2/';
				return [
					'body'     => '{"id": "TEST_CLIENT_GRANT_ID","client_id": "TEST_CLIENT_ID",
						"audience": "' . $audience . '","scope": ["read:users","update:users"]}',
					'response' => [ 'code' => 201 ],
				];
		}

		return $this->httpMockDefault( $response_type );
	}
}
