<?php
/**
 * Quotation tracker template.
 *
 * @var string $quote_id
 * @var array<string,mixed>|null $status
 * @var string $error
 */
?>
<div class="vec-my-account-section">
    <h2><?php esc_html_e( 'Quotation Tracker', 'victoria-erp-connector' ); ?></h2>

    <?php if ( $error !== '' ) : ?>
        <div class="vec-error-message"><?php echo esc_html( $error ); ?></div>
    <?php endif; ?>

    <form method="post">
        <?php wp_nonce_field( 'vec_quote_tracker', 'vec_quote_nonce' ); ?>
        <p>
            <label for="vec_quote_id"><?php esc_html_e( 'Quote ID', 'victoria-erp-connector' ); ?></label>
            <input type="text" id="vec_quote_id" name="vec_quote_id" value="<?php echo esc_attr( $quote_id ); ?>" class="input-text" />
        </p>
        <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Check Quote Status', 'victoria-erp-connector' ); ?></button></p>
    </form>

    <?php if ( ! empty( $status ) ) : ?>
        <div class="vec-quote-status">
            <h3><?php esc_html_e( 'Quote Status', 'victoria-erp-connector' ); ?></h3>
            <pre><?php echo esc_html( wp_json_encode( $status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
        </div>
    <?php endif; ?>
</div>
