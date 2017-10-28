
var CampTixPaystack = new function() {
	var self = this;

	self.data = CampTixPaystackData;
	self.form = false;

	self.init = function() {
		self.form = jQuery( '#tix form' );
		self.form.on( 'submit', CampTixPaystack.form_handler );

		// On a failed attendee data request, we'll have the previous stripe token
		if ( self.data.token ) {
			self.add_stripe_token_hidden_fields( self.data.token, self.data.receipt_email || '' );
		}
	}

	self.form_handler = function(e) {
		// Verify Stripe is the selected method.
		var method = self.form.find('[name="tix_payment_method"]').val() || 'paystack';

		if ( 'paystack' != method ) {
			return;
		}

		// If the form already has a Stripe token, bail.
		var tokenised = self.form.find('input[name="tix_paystack_token"]');
		if ( tokenised.length ) {
			return;
		}

		self.paystack_checkout();

		e.preventDefault();
	}

	self.paystack_checkout = function() {

		var emails = jQuery.uniqueSort(
			self.form.find('input[type="email"]')
			.filter( function () { return this.value.length; })
			.map( function() { return this.value; } )
		);

        var handler = PaystackPop.setup({
            key: self.data.key,
            email: ( emails.length == 1 ? emails[0] : '' ) || '',
            amount: parseInt( this.data.amount ),
            currency: 'NGN',
            callback: self.paystack_token_callback,
            onClose: function() {
                handler.closeIframe();
            }
        });

        console.log( handler );

        handler.openIframe();

        return false;

	};

	self.paystack_token_callback = function( response ) {

        self.form.append( '<input type="hidden" class="paystack_txnref" name="paystack_txnref" value="' + response.trxref + '"/>' );
        paystack_submit = true;

        self.form.submit();
	}

};

jQuery(document).ready( function($) {
	CampTixPaystack.init()
});
