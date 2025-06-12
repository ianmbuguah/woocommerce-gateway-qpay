/**
 * External dependencies
 */
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';

/**
 * Internal dependencies
 */
import { PAYMENT_METHOD_NAME } from './constants';
import { getQpayServerData } from './qpay-utils';

const Content = () => {
	return decodeEntities(getQpayServerData()?.description || '');
};

const Label = () => {
	return (
		<img
			src={getQpayServerData()?.logo_url}
			alt={getQpayServerData()?.title}
		/>
	);
};

registerPaymentMethod({
	name: PAYMENT_METHOD_NAME,
	label: <Label />,
	ariaLabel: __('Qpay payment method', 'woocommerce-gateway-qpay'),
	canMakePayment: () => true,
	content: <Content />,
	edit: <Content />,
	supports: {
		features: getQpayServerData()?.supports ?? [],
	},
});