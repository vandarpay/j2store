<?php
/**
 * Vandar payment plugin
 *
 * @publisher     Vandar
 * @package       J2Store
 * @subpackage    payment
 * @copyright (C) 2020 Vandar
 * @license       http://www.gnu.org/licenses/gpl-2.0.html GPLv2 or later
 *
 * https://vandar.io
 */
defined( '_JEXEC' ) or die( 'Restricted access' );
?>

<form action="<?php echo @$vars->vandar; ?>" method="get" name="adminForm" enctype="multipart/form-data">
    <p>
        <img src="/plugins/j2store/payment_vandar/payment_vandar/logo.svg" style="display: inline-block;vertical-align: middle;width: 70px;">
        <?php echo JText::_("PLG_J2STORE_VANDAR_OPTION_NAME"); ?>
    </p>
    <br/>
    <?php if(!empty(@$vars->error)): ?>
        <div class="warning alert alert-danger">
            <?php echo @$vars->error?>
        </div>
    <?php else:?>
        <input type="submit" class="j2store_cart_button button btn btn-primary"
               value="<?php echo JText::_( $vars->button_text ); ?>"/>
    <?php endif; ?>
</form>
