<?php
namespace CodoDigital\Mailer\Tests\Unit\Transport;

use CodoDigital\Mailer\Tests\TestCase;
use CodoDigital\Mailer\Transport\AwsSigV4;

class AwsSigV4Test extends TestCase {

	const ACCESS = 'AKIDEXAMPLE';
	const SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

	const TOKEN = 'AQoDYXdzEPT//////////wEXAMPLEtc764bNrC9SAPBSM22wDOk4x4HIZ8j4FZTwdQWLWsKWHGBuFqwAeMicRXmxfpSPfIeoIYRqTflfKD8YUuwthAx7mSEI/qkPpKPi/kMcGdQrmGdeehM4IC1NtBmUpp2wUE8phUZampKsburEDy0KPkyQDYwT7WZ0wq5VSXDvp75YU9HFvlRd8Tx6q6fE8YQcHNVXAkiY9q6d+xo0rKwT38xVqr7ZD0u0iPPkUL64lIZbqBAz+scqKmlzm8FDrypNC9Yjc8fPOLn9FX9KSYvKTr4rvx3iSIlTJabIQwj2ICCR/oLxBA==';

	private function sign( $method, $url, array $headers = array(), $body = '', $token = '' ) {
		return ( new AwsSigV4() )->sign( $method, $url, $headers, $body, 'us-east-1', 'service', self::ACCESS, self::SECRET, gmmktime( 12, 36, 0, 8, 30, 2015 ), $token );
	}

	/**
	 * Every case from the AWS Signature Version 4 test suite that this
	 * signer's input can express (it takes one value per header, so the
	 * duplicate-header and multi-line-value cases are left out; so is
	 * post-sts-header-after, which leaves the token out of the signature).
	 *
	 * @link https://docs.aws.amazon.com/IAM/latest/UserGuide/signature-v4-test-suite.html
	 * @dataProvider aws_test_suite
	 */
	public function test_aws_signature_v4_test_suite( $method, $url, array $headers, $body, $token, $signature ) {
		$signed = $this->sign( $method, $url, $headers, $body, $token );

		$this->assertStringStartsWith( 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=', $signed['Authorization'] );
		$this->assertStringEndsWith( 'Signature=' . $signature, $signed['Authorization'] );
	}

	/**
	 * Cases from the AWS SigV4 test suite (aws-sig-v4-test-suite, 2015-08-30;
	 * Copyright Amazon.com, Inc. or its affiliates, Apache License 2.0):
	 * the request, and the signature AWS publishes for it.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, string>, 3: string, 4: string, 5: string}>
	 */
	public function aws_test_suite() {
		return array(
			'get-header-value-trim' => array(
				'GET',
				'https://example.amazonaws.com/',
				array(
					'My-Header1' => ' value1',
					'My-Header2' => ' "a   b   c"',
				),
				'',
				'',
				'acc3ed3afb60bb290fc8d2dd0098b9911fcaa05412b367055dee359757a9c736',
			),
			'get-relative' => array(
				'GET',
				'https://example.amazonaws.com/example/..',
				array(),
				'',
				'',
				'5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
			),
			'get-relative-relative' => array(
				'GET',
				'https://example.amazonaws.com/example1/example2/../..',
				array(),
				'',
				'',
				'5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
			),
			'get-slash' => array(
				'GET',
				'https://example.amazonaws.com//',
				array(),
				'',
				'',
				'5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
			),
			'get-slash-dot-slash' => array(
				'GET',
				'https://example.amazonaws.com/./',
				array(),
				'',
				'',
				'5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
			),
			'get-slash-pointless-dot' => array(
				'GET',
				'https://example.amazonaws.com/./example',
				array(),
				'',
				'',
				'ef75d96142cf21edca26f06005da7988e4f8dc83a165a80865db7089db637ec5',
			),
			'get-slashes' => array(
				'GET',
				'https://example.amazonaws.com//example//',
				array(),
				'',
				'',
				'9a624bd73a37c9a373b5312afbebe7a714a789de108f0bdfe846570885f57e84',
			),
			'get-space' => array(
				'GET',
				'https://example.amazonaws.com/example space/',
				array(),
				'',
				'',
				'652487583200325589f1fba4c7e578f72c47cb61beeca81406b39ddec1366741',
			),
			'get-special-character' => array(
				'GET',
				'https://example.amazonaws.com/example/$delete',
				array(),
				'',
				'',
				'a853c9b21b528b19643d00910d35b83a10c366a10833ceefb45edd6c80e40f27',
			),
			'get-unreserved' => array(
				'GET',
				'https://example.amazonaws.com/-._~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz',
				array(),
				'',
				'',
				'07ef7494c76fa4850883e2b006601f940f8a34d404d0cfa977f52a65bbf5f24f',
			),
			'get-utf8' => array(
				'GET',
				'https://example.amazonaws.com/ሴ',
				array(),
				'',
				'',
				'8318018e0b0f223aa2bbf98705b62bb787dc9c0e678f255a891fd03141be5d85',
			),
			'get-vanilla' => array(
				'GET',
				'https://example.amazonaws.com/',
				array(),
				'',
				'',
				'5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
			),
			'get-vanilla-empty-query-key' => array(
				'GET',
				'https://example.amazonaws.com/?Param1=value1',
				array(),
				'',
				'',
				'a67d582fa61cc504c4bae71f336f98b97f1ea3c7a6bfe1b6e45aec72011b9aeb',
			),
			'get-vanilla-query' => array(
				'GET',
				'https://example.amazonaws.com/',
				array(),
				'',
				'',
				'5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
			),
			'get-vanilla-query-order-encoded' => array(
				'GET',
				'https://example.amazonaws.com/?Param-3=Value3&Param=Value2&%E1%88%B4=Value1',
				array(),
				'',
				'',
				'371d3713e185cc334048618a97f809c9ffe339c62934c032af5a0e595648fcac',
			),
			'get-vanilla-query-order-key' => array(
				'GET',
				'https://example.amazonaws.com/?Param1=value2&Param1=Value1',
				array(),
				'',
				'',
				'eedbc4e291e521cf13422ffca22be7d2eb8146eecf653089df300a15b2382bd1',
			),
			'get-vanilla-query-order-key-case' => array(
				'GET',
				'https://example.amazonaws.com/?Param2=value2&Param1=value1',
				array(),
				'',
				'',
				'b97d918cfa904a5beff61c982a1b6f458b799221646efd99d3219ec94cdf2500',
			),
			'get-vanilla-query-order-value' => array(
				'GET',
				'https://example.amazonaws.com/?Param1=value2&Param1=value1',
				array(),
				'',
				'',
				'5772eed61e12b33fae39ee5e7012498b51d56abc0abb7c60486157bd471c4694',
			),
			'get-vanilla-query-unreserved' => array(
				'GET',
				'https://example.amazonaws.com/?-._~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz=-._~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz',
				array(),
				'',
				'',
				'9c3e54bfcdf0b19771a7f523ee5669cdf59bc7cc0884027167c21bb143a40197',
			),
			'get-vanilla-utf8-query' => array(
				'GET',
				'https://example.amazonaws.com/?ሴ=bar',
				array(),
				'',
				'',
				'2cdec8eed098649ff3a119c94853b13c643bcf08f8b0a1d91e12c9027818dd04',
			),
			'get-vanilla-with-session-token' => array(
				'GET',
				'https://example.amazonaws.com/',
				array(),
				'',
				'6e86291e8372ff2a2260956d9b8aae1d763fbf315fa00fa31553b73ebf194267',
				'07ec1639c89043aa0e3e2de82b96708f198cceab042d4a97044c66dd9f74e7f8',
			),
			'post-header-key-case' => array(
				'POST',
				'https://example.amazonaws.com/',
				array(),
				'',
				'',
				'5da7c1a2acd57cee7505fc6676e4e544621c30862966e37dddb68e92efbe5d6b',
			),
			'post-header-key-sort' => array(
				'POST',
				'https://example.amazonaws.com/',
				array(
					'My-Header1' => 'value1',
				),
				'',
				'',
				'c5410059b04c1ee005303aed430f6e6645f61f4dc9e1461ec8f8916fdf18852c',
			),
			'post-header-value-case' => array(
				'POST',
				'https://example.amazonaws.com/',
				array(
					'My-Header1' => 'VALUE1',
				),
				'',
				'',
				'cdbc9802e29d2942e5e10b5bccfdd67c5f22c7c4e8ae67b53629efa58b974b7d',
			),
			'post-sts-header-before' => array(
				'POST',
				'https://example.amazonaws.com/',
				array(),
				'',
				'AQoDYXdzEPT//////////wEXAMPLEtc764bNrC9SAPBSM22wDOk4x4HIZ8j4FZTwdQWLWsKWHGBuFqwAeMicRXmxfpSPfIeoIYRqTflfKD8YUuwthAx7mSEI/qkPpKPi/kMcGdQrmGdeehM4IC1NtBmUpp2wUE8phUZampKsburEDy0KPkyQDYwT7WZ0wq5VSXDvp75YU9HFvlRd8Tx6q6fE8YQcHNVXAkiY9q6d+xo0rKwT38xVqr7ZD0u0iPPkUL64lIZbqBAz+scqKmlzm8FDrypNC9Yjc8fPOLn9FX9KSYvKTr4rvx3iSIlTJabIQwj2ICCR/oLxBA==',
				'85d96828115b5dc0cfc3bd16ad9e210dd772bbebba041836c64533a82be05ead',
			),
			'post-vanilla' => array(
				'POST',
				'https://example.amazonaws.com/',
				array(),
				'',
				'',
				'5da7c1a2acd57cee7505fc6676e4e544621c30862966e37dddb68e92efbe5d6b',
			),
			'post-vanilla-empty-query-value' => array(
				'POST',
				'https://example.amazonaws.com/?Param1=value1',
				array(),
				'',
				'',
				'28038455d6de14eafc1f9222cf5aa6f1a96197d7deb8263271d420d138af7f11',
			),
			'post-vanilla-query' => array(
				'POST',
				'https://example.amazonaws.com/?Param1=value1',
				array(),
				'',
				'',
				'28038455d6de14eafc1f9222cf5aa6f1a96197d7deb8263271d420d138af7f11',
			),
			'post-x-www-form-urlencoded' => array(
				'POST',
				'https://example.amazonaws.com/',
				array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'Param1=value1',
				'',
				'ff11897932ad3f4e8b18135d722051e5ac45fc38421b1da7b9d196a0fe09473a',
			),
			'post-x-www-form-urlencoded-parameters' => array(
				'POST',
				'https://example.amazonaws.com/',
				array(
					'Content-Type' => 'application/x-www-form-urlencoded; charset=utf8',
				),
				'Param1=value1',
				'',
				'1a72ec8f64bd914b0e42e42607c7fbce7fb2c7465f63e3092b3b0d39fa77a6fe',
			),
		);
	}

	/**
	 * AWS Signature V4 test suite, "get-vanilla".
	 */
	public function test_matches_the_official_aws_test_vector() {
		$headers = $this->sign( 'GET', 'https://example.amazonaws.com/' );

		$this->assertSame( '20150830T123600Z', $headers['x-amz-date'] );
		$this->assertSame(
			'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
			$headers['Authorization']
		);
		$this->assertArrayNotHasKey( 'host', $headers, 'the HTTP client sets Host itself' );
	}

	public function test_missing_path_is_treated_as_root() {
		$this->assertSame(
			$this->sign( 'GET', 'https://example.amazonaws.com/' )['Authorization'],
			$this->sign( 'GET', 'https://example.amazonaws.com' )['Authorization']
		);
	}

	public function test_query_parameters_are_sorted() {
		$this->assertSame(
			$this->sign( 'GET', 'https://example.amazonaws.com/?b=2&a=1&a=0&flag' )['Authorization'],
			$this->sign( 'GET', 'https://example.amazonaws.com/?a=0&a=1&b=2&flag=' )['Authorization']
		);
	}

	public function test_header_names_are_case_insensitive_and_values_trimmed() {
		$this->assertSame(
			$this->sign( 'POST', 'https://example.amazonaws.com/x', array( 'Content-Type' => '  application/json ' ), '{}' )['Authorization'],
			$this->sign( 'POST', 'https://example.amazonaws.com/x', array( 'content-type' => 'application/json' ), '{}' )['Authorization']
		);
	}

	public function test_body_is_part_of_the_signature() {
		$this->assertNotSame(
			$this->sign( 'POST', 'https://example.amazonaws.com/x', array(), '{"a":1}' )['Authorization'],
			$this->sign( 'POST', 'https://example.amazonaws.com/x', array(), '{"a":2}' )['Authorization']
		);
	}

	public function test_signed_headers_include_custom_headers() {
		$headers = $this->sign( 'POST', 'https://example.amazonaws.com/v2/x', array( 'content-type' => 'application/json' ), '{}' );
		$this->assertStringContainsString( 'SignedHeaders=content-type;host;x-amz-date', $headers['Authorization'] );
		$this->assertSame( 'application/json', $headers['content-type'] );
	}

	public function test_session_token_is_sent_and_signed() {
		$headers = $this->sign( 'POST', 'https://example.amazonaws.com/', array(), '', self::TOKEN );

		$this->assertSame( self::TOKEN, $headers['x-amz-security-token'] );
		$this->assertStringContainsString( 'SignedHeaders=host;x-amz-date;x-amz-security-token,', $headers['Authorization'] );
	}

	public function test_no_token_header_without_a_session_token() {
		$headers = $this->sign( 'POST', 'https://example.amazonaws.com/' );

		$this->assertArrayNotHasKey( 'x-amz-security-token', $headers );
		$this->assertStringContainsString( 'SignedHeaders=host;x-amz-date,', $headers['Authorization'] );
	}

	/**
	 * Non-S3 services expect the path to be encoded twice, so an encoded
	 * character in the URL is encoded again for the signature.
	 */
	public function test_an_encoded_path_is_encoded_again() {
		$canonical_path = new \ReflectionMethod( AwsSigV4::class, 'canonical_path' );
		$canonical_path->setAccessible( true );

		$this->assertSame( '/example%2520space/', $canonical_path->invoke( new AwsSigV4(), '/example%20space/' ) );
		$this->assertSame( '/v2/email/outbound-emails', $canonical_path->invoke( new AwsSigV4(), '/v2/email/outbound-emails' ), 'the SES path is unchanged' );
		$this->assertNotSame(
			$this->sign( 'GET', 'https://example.amazonaws.com/example space/' )['Authorization'],
			$this->sign( 'GET', 'https://example.amazonaws.com/example%20space/' )['Authorization']
		);
	}
}
