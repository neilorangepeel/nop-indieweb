<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Tests;

use PHPUnit\Framework\TestCase;

use function NOP\IndieWeb\nop_indieweb_haversine_km;
use function NOP\IndieWeb\nop_indieweb_is_airport_venue;
use function NOP\IndieWeb\nop_indieweb_flight_arc_points;

/**
 * Unit tests for the pure flight-pairing / arc geometry helpers: airport
 * detection, great-circle distance, and the quadratic-Bézier arc sampler
 * (endpoints, northward bulge, coincident guard, antimeridian unwrap). No
 * WordPress, network, or DB involved.
 */
final class FlightArcTest extends TestCase {

	public function test_airport_detection(): void {
		$this->assertTrue( nop_indieweb_is_airport_venue( [ 'Airport' ] ) );
		$this->assertTrue( nop_indieweb_is_airport_venue( [ 'International Airport' ] ) );
		$this->assertTrue( nop_indieweb_is_airport_venue( [ 'Bar', 'Airport Lounge' ] ) );
		$this->assertTrue( nop_indieweb_is_airport_venue( [ 'Airfield' ] ) );
		$this->assertFalse( nop_indieweb_is_airport_venue( [ 'Bar', 'Pub' ] ) );
		$this->assertFalse( nop_indieweb_is_airport_venue( [] ) );
	}

	public function test_haversine_km(): void {
		// Belfast International → London Heathrow ≈ 520 km great-circle.
		$km = nop_indieweb_haversine_km( 54.6575, -6.2158, 51.4706, -0.4619 );
		$this->assertGreaterThan( 480.0, $km );
		$this->assertLessThan( 560.0, $km );

		$this->assertSame( 0.0, nop_indieweb_haversine_km( 51.5, -0.1, 51.5, -0.1 ) );
	}

	public function test_arc_endpoints_and_count(): void {
		$pts = nop_indieweb_flight_arc_points( [ 54.6575, -6.2158 ], [ 51.4706, -0.4619 ] );

		$this->assertCount( 25, $pts ); // 24 steps → 25 points.
		$this->assertEqualsWithDelta( 54.6575, $pts[0][0], 0.0001 );
		$this->assertEqualsWithDelta( -6.2158, $pts[0][1], 0.0001 );
		$this->assertEqualsWithDelta( 51.4706, $pts[24][0], 0.0001 );
		$this->assertEqualsWithDelta( -0.4619, $pts[24][1], 0.0001 );
	}

	public function test_arc_bulges_north(): void {
		// A due east-west leg at 50°N; the mid-point of the arc must rise north.
		$pts = nop_indieweb_flight_arc_points( [ 50.0, -10.0 ], [ 50.0, 10.0 ] );
		$this->assertGreaterThan( 50.5, $pts[12][0] );
	}

	public function test_coincident_points_return_empty(): void {
		$this->assertSame( [], nop_indieweb_flight_arc_points( [ 50.0, 5.0 ], [ 50.0, 5.0 ] ) );
	}

	public function test_antimeridian_takes_short_way(): void {
		// 170°E → 170°W is a 20° hop over the dateline, not 340° the long way.
		$pts = nop_indieweb_flight_arc_points( [ 40.0, 170.0 ], [ 40.0, -170.0 ] );

		$this->assertEqualsWithDelta( 170.0, $pts[0][1], 0.01 );
		$this->assertEqualsWithDelta( -170.0, $pts[24][1], 0.01 );
		// The mid-point sits near the antimeridian (±180), never swinging back to 0.
		$this->assertGreaterThan( 179.0, abs( $pts[12][1] ) );
	}
}
