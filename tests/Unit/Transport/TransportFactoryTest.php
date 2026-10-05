<?php
namespace CodoDigital\Mailer\Tests\Unit\Transport;

use Brain\Monkey\Filters;
use CodoDigital\Mailer\Tests\TestCase;
use CodoDigital\Mailer\Transport\BrevoTransport;
use CodoDigital\Mailer\Transport\MailgunTransport;
use CodoDigital\Mailer\Transport\PostmarkTransport;
use CodoDigital\Mailer\Transport\SendGridTransport;
use CodoDigital\Mailer\Transport\SesTransport;
use CodoDigital\Mailer\Transport\SmtpTransport;
use CodoDigital\Mailer\Transport\TransportFactory;
use CodoDigital\Mailer\Transport\TransportInterface;

class TransportFactoryTest extends TestCase {

	/**
	 * @dataProvider types
	 */
	public function test_creates_each_built_in_type( $type, $class ) {
		$this->assertInstanceOf( $class, ( new TransportFactory() )->create( array( 'type' => $type ) ) );
	}

	public function types() {
		return array(
			array( 'smtp', SmtpTransport::class ),
			array( 'ses', SesTransport::class ),
			array( 'postmark', PostmarkTransport::class ),
			array( 'mailgun', MailgunTransport::class ),
			array( 'brevo', BrevoTransport::class ),
			array( 'sendgrid', SendGridTransport::class ),
		);
	}

	public function test_none_and_missing_type_return_null() {
		$factory = new TransportFactory();
		$this->assertNull( $factory->create( array( 'type' => 'none' ) ) );
		$this->assertNull( $factory->create( array() ) );
	}

	public function test_extensions_can_supply_a_transport() {
		$custom = \Mockery::mock( TransportInterface::class );
		Filters\expectApplied( 'codo_mailer_transport' )->once()->with( null, array( 'type' => 'custom' ) )->andReturn( $custom );

		$this->assertSame( $custom, ( new TransportFactory() )->create( array( 'type' => 'custom' ) ) );
	}

	public function test_filter_returning_garbage_is_ignored() {
		Filters\expectApplied( 'codo_mailer_transport' )->andReturn( new \stdClass() );
		$this->assertNull( ( new TransportFactory() )->create( array( 'type' => 'custom' ) ) );
	}
}
