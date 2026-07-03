<?php
/**
 * ERP profile template.
 *
 * @var array<string,mixed> $profile
 * @var string $error
 */
?>
<div class="vec-my-account-section">
    <h2><?php esc_html_e( 'ERP Profile', 'victoria-erp-connector' ); ?></h2>

    <?php if ( $error !== '' ) : ?>
        <div class="vec-error-message"><?php echo esc_html( $error ); ?></div>
    <?php endif; ?>

    <?php if ( empty( $profile ) ) : ?>
        <p><?php esc_html_e( 'No ERP profile data available.', 'victoria-erp-connector' ); ?></p>
        <?php return; ?>
    <?php endif; ?>

    <table class="shop_table shop_table_responsive vec-erp-profile">
        <tbody>
            <?php foreach ( $profile as $label => $value ) : ?>
                <tr>
                    <th><?php echo esc_html( ucwords( str_replace( '_', ' ', $label ) ) ); ?></th>
                    <td><?php echo esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
