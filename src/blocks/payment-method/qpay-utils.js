/**
 * External dependencies
 */
import { getSetting } from '@woocommerce/settings';

/**
 * Qpay data comes form the server passed on a global object.
 */
export const getQpayServerData = () => {
	const qpayServerData = getSetting('qpay_data', null);
	if (!qpayServerData) {
		throw new Error('Qpay initialization data is not available');
	}
	return qpayServerData;
};