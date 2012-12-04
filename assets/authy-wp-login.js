( function( $ ) {
	$( document ).ready( function() {
		// Build link
		var link = $( '#authy-send-sms' ),
			ajax = AuthyForWP.ajax,
			username = $( '#user_login' ).val();

		if ( username )
			ajax += '&username=' + username;

		ajax += '&KeepThis=true&TB_iframe=true&height=250&width=450';

		link.attr( 'href', ajax );
		link.addClass( 'thickbox' );

		// Capture provided username
		$( '#user_login' ).on( 'keyup keydown keypress', function() {
			var username = $( this ).val();
			// console.log( ajax.indexOf( 'username' ) );
			if ( -1 == ajax.indexOf( 'username' ) ) {
				ajax += '&username=' + username;
			} else {
				ajax = ajax.replace( /username[=]?[^&]*/i, 'username=' + username, ajax );
			}

			link.attr( 'href', ajax );
		} );
	} );
} )( jQuery );