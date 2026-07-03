<?php
/**
 * Purchase history template.
 *
 * @var array<string,mixed> $history
 * @var string $error
 */
?>
<div class="vec-my-account-section">
    <h2><?php esc_html_e( 'Purchase History', 'victoria-erp-connector' ); ?></h2>

    <?php if ( $error !== '' ) : ?>
        <div class="vec-error-message"><?php echo esc_html( $error ); ?></div>
    <?php endif; ?>

    <?php if ( empty( $history ) ) : ?>
        <p><?php esc_html_e( 'No purchase history available.', 'victoria-erp-connector' ); ?></p>
        <?php return; ?>
    <?php endif; ?>

    <table class="shop_table shop_table_responsive vec-purchase-history">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Order', 'victoria-erp-connector' ); ?></th>
                <th><?php esc_html_e( 'Date', 'victoria-erp-connector' ); ?></th>
                <th><?php esc_html_e( 'Total', 'victoria-erp-connector' ); ?></th>
                <th><?php esc_html_e( 'Status', 'victoria-erp-connector' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $history as $item ) : ?>
                <tr>
                    <td><?php echo esc_html( $item['order_number'] ?? $item['order_id'] ?? '-' ); ?></td>
                    <td><?php echo esc_html( $item['date'] ?? '-' ); ?></td>
                    <td><?php echo esc_html( $item['total'] ?? '-' ); ?></td>
                    <td><?php echo esc_html( $item['status'] ?? '-' ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
