<?php
/**
 * AWS Signature Version 4 request signing.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal SigV4 signer, so the plugin needs no AWS SDK.
 *
 * @link https://docs.aws.amazon.com/IAM/latest/UserGuide/create-signed-request.html
 */
final class AwsSigV4 {

	/**
	 * Sign a request and return the headers to send.
	 *
	 * @param string                $method     HTTP method.
	 * @param string                $url        Full URL.
	 * @param array<string, string> $headers    Headers to sign (host is added).
	 * @param string                $body       Request body.
	 * @param string                $region     AWS region.
	 * @param string                $service    AWS service name.
	 * @param string                $access_key Access key ID.
	 * @param string                $secret_key Secret access key.
	 * @param int                   $timestamp  Unix timestamp of the request.
	 * @return array<string, string> Headers including Authorization and X-Amz-Date.
	 */
	public function sign( $method, $url, array $headers, $body, $region, $service, $access_key, $secret_key, $timestamp ) {
		$parts    = wp_parse_url( $url );
		$host     = isset( $parts['host'] ) ? $parts['host'] : '';
		$path     = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		$query    = isset( $parts['query'] ) ? $parts['query'] : '';
		$amz_date = gmdate( 'Ymd\THis\Z', $timestamp );
		$date     = gmdate( 'Ymd', $timestamp );

		$headers['host']       = $host;
		$headers['x-amz-date'] = $amz_date;

		$canonical = array();
		foreach ( $headers as $name => $value ) {
			$canonical[ strtolower( trim( $name ) ) ] = preg_replace( '/\s+/', ' ', trim( (string) $value ) );
		}
		ksort( $canonical );

		$canonical_headers = '';
		foreach ( $canonical as $name => $value ) {
			$canonical_headers .= $name . ':' . $value . "\n";
		}
		$signed_headers = implode( ';', array_keys( $canonical ) );

		$canonical_request = implode(
			"\n",
			array(
				strtoupper( $method ),
				$this->canonical_path( $path ),
				$this->canonical_query( $query ),
				$canonical_headers,
				$signed_headers,
				hash( 'sha256', (string) $body ),
			)
		);

		$scope          = $date . '/' . $region . '/' . $service . '/aws4_request';
		$string_to_sign = "AWS4-HMAC-SHA256\n" . $amz_date . "\n" . $scope . "\n" . hash( 'sha256', $canonical_request );

		$key       = hash_hmac( 'sha256', $date, 'AWS4' . $secret_key, true );
		$key       = hash_hmac( 'sha256', $region, $key, true );
		$key       = hash_hmac( 'sha256', $service, $key, true );
		$key       = hash_hmac( 'sha256', 'aws4_request', $key, true );
		$signature = hash_hmac( 'sha256', $string_to_sign, $key );

		$headers['Authorization'] = sprintf(
			'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			$access_key,
			$scope,
			$signed_headers,
			$signature
		);

		unset( $headers['host'] );
		return $headers;
	}

	/**
	 * URI-encode each path segment.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function canonical_path( $path ) {
		$segments = explode( '/', $path );
		foreach ( $segments as $i => $segment ) {
			$segments[ $i ] = rawurlencode( rawurldecode( $segment ) );
		}
		return implode( '/', $segments );
	}

	/**
	 * Sort and encode a query string.
	 *
	 * @param string $query Raw query string.
	 * @return string
	 */
	private function canonical_query( $query ) {
		if ( '' === $query ) {
			return '';
		}
		$pairs = array();
		foreach ( explode( '&', $query ) as $pair ) {
			$kv      = explode( '=', $pair, 2 );
			$pairs[] = array(
				rawurlencode( rawurldecode( $kv[0] ) ),
				rawurlencode( rawurldecode( isset( $kv[1] ) ? $kv[1] : '' ) ),
			);
		}
		usort(
			$pairs,
			static function ( $a, $b ) {
				return 0 !== strcmp( $a[0], $b[0] ) ? strcmp( $a[0], $b[0] ) : strcmp( $a[1], $b[1] );
			}
		);
		return implode(
			'&',
			array_map(
				static function ( $p ) {
					return $p[0] . '=' . $p[1];
				},
				$pairs
			)
		);
	}
}
