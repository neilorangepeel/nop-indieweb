import { ordinal, tkDur, parseShareParams } from './lib';

describe( 'ordinal', () => {
	test( 'basic suffixes', () => {
		expect( ordinal( 1 ) ).toBe( '1st' );
		expect( ordinal( 2 ) ).toBe( '2nd' );
		expect( ordinal( 3 ) ).toBe( '3rd' );
		expect( ordinal( 4 ) ).toBe( '4th' );
	} );
	test( 'the teens are all -th', () => {
		expect( ordinal( 11 ) ).toBe( '11th' );
		expect( ordinal( 12 ) ).toBe( '12th' );
		expect( ordinal( 13 ) ).toBe( '13th' );
	} );
	test( 'compound numbers', () => {
		expect( ordinal( 21 ) ).toBe( '21st' );
		expect( ordinal( 102 ) ).toBe( '102nd' );
		expect( ordinal( 113 ) ).toBe( '113th' );
	} );
} );

describe( 'tkDur', () => {
	test( 'sub-hour rounds to minutes', () => {
		expect( tkDur( 0 ) ).toBe( '0m' );
		expect( tkDur( 45 ) ).toBe( '1m' );
		expect( tkDur( 22 * 60 ) ).toBe( '22m' );
		expect( tkDur( 59 * 60 ) ).toBe( '59m' );
	} );
	test( 'an hour and over splits h + m', () => {
		expect( tkDur( 60 * 60 ) ).toBe( '1h 0m' );
		expect( tkDur( ( 5 * 60 + 42 ) * 60 ) ).toBe( '5h 42m' );
	} );
} );

describe( 'parseShareParams', () => {
	test( 'no params → no intent', () => {
		expect( parseShareParams( '' ) ).toEqual( { kind: '', url: '', text: '' } );
	} );
	test( 'a shared link becomes a bookmark', () => {
		expect( parseShareParams( '?url=https://example.org/a' ) )
			.toEqual( { kind: 'bookmark', url: 'https://example.org/a', text: '' } );
	} );
	test( 'a shared link carries the title as notes', () => {
		expect( parseShareParams( '?url=https://example.org/a&title=Cool' ) )
			.toEqual( { kind: 'bookmark', url: 'https://example.org/a', text: 'Cool' } );
	} );
	test( 'shared text becomes a note', () => {
		expect( parseShareParams( '?text=hello' ) )
			.toEqual( { kind: 'note', url: '', text: 'hello' } );
	} );
	test( 'an explicit kind wins over url', () => {
		expect( parseShareParams( '?reply=https://x.com/p&url=https://y.com' ) )
			.toEqual( { kind: 'reply', url: 'https://x.com/p', text: '' } );
	} );
	test( 'explicit kind keeps its text but not the title fallback', () => {
		expect( parseShareParams( '?bookmark=https://x.com&note=why' ) )
			.toEqual( { kind: 'bookmark', url: 'https://x.com', text: 'why' } );
	} );
} );
