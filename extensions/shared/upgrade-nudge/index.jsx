/**
 * External dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { Button } from '@wordpress/components';
import { compact, get, startsWith } from 'lodash';
import { compose } from '@wordpress/compose';
import { withDispatch, withSelect } from '@wordpress/data';
import { Warning } from '@wordpress/editor';
import Gridicon from 'gridicons';

/**
 * Internal dependencies
 */
import analytics from '../../../_inc/client/lib/analytics';
import getSiteFragment from '../get-site-fragment';
import './store';

import './style.scss';

const UpgradeNudge = ( { autosaveAndRedirectToUpgrade, planName, upgradeUrl } ) => (
	<Warning
		actions={
			// Use upgradeUrl to determine whether or not to display the Upgrade button.
			// We tried setting autosaveAndRedirectToUpgrade to falsey in `withDispatch`,
			// but it doesn't seem to be reliably updated after a `withSelect` update.
			upgradeUrl && [
				<Button onClick={ autosaveAndRedirectToUpgrade } target="_top" isDefault>
					{ __( 'Upgrade', 'jetpack' ) }
				</Button>,
			]
		}
		className="jetpack-upgrade-nudge"
	>
		<div className="jetpack-upgrade-nudge__info">
			<Gridicon className="jetpack-upgrade-nudge__icon" icon="star" size={ 18 } />
			<div>
				<span className="jetpack-upgrade-nudge__title">
					{ planName
						? sprintf( __( 'This block is available under the %(planName)s Plan.', 'jetpack' ), {
								planName,
						  } )
						: __( 'This block requires a paid plan.', 'jetpack' ) }
				</span>
				<span className="jetpack-upgrade-nudge__message">
					{ __( 'It will be hidden from site visitors until you upgrade.', 'jetpack' ) }
				</span>
			</div>
		</div>
	</Warning>
);

export default compose( [
	withSelect( ( select, { plan: planSlug } ) => {
		const plan = select( 'wordpress-com/plans' ).getPlan( planSlug );

		// WP.com plan objects have a dedicated `path_slug` field, Jetpack plan objects don't
		// For Jetpack, we thus use the plan slug with the 'jetpack_' prefix removed.
		const planPathSlug = startsWith( planSlug, 'jetpack_' )
			? planSlug.substr( 'jetpack_'.length )
			: get( plan, [ 'path_slug' ] );

		const postId = select( 'core/editor' ).getCurrentPostId();
		const postType = select( 'core/editor' ).getCurrentPostType();

		// The editor for CPTs has an `edit/` route fragment prefixed
		const postTypeEditorRoutePrefix = [ 'page', 'post' ].includes( postType ) ? '' : 'edit';

		const upgradeUrl =
			planPathSlug &&
			addQueryArgs( `https://wordpress.com/checkout/${ getSiteFragment() }/${ planPathSlug }`, {
				redirect_to:
					'/' +
					compact( [ postTypeEditorRoutePrefix, postType, getSiteFragment(), postId ] ).join( '/' ),
			} );
		return {
			planName: get( plan, [ 'product_name_short' ] ),
			upgradeUrl,
		};
	} ),
	withDispatch( ( dispatch, { blockName, plan, upgradeUrl } ) => ( {
		autosaveAndRedirectToUpgrade: async () => {
			analytics.tracks.recordEvent( 'jetpack_editor_block_upgrade_click', {
				plan,
				block: blockName,
			} );
			await dispatch( 'core/editor' ).autosave();
			// Using window.top to escape from the editor iframe on WordPress.com
			window.top.location.href = upgradeUrl;
		},
	} ) ),
] )( UpgradeNudge );
