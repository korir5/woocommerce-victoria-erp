<?php
/**
 * Quote tracker shortcode template.
 *
 * @var string $quote_id
 * @var array<string,mixed>|null $result
 * @var string $error
 */
?>
<div class="vec-quote-tracker">
    <form method="post" class="vec-quote-tracker__form">
        <?php wp_nonce_field( 'vec_quote_tracker', 'vec_quote_tracker_nonce' ); ?>

        <label for="vec_quote_id" class="vec-quote-tracker__label">
            <?php esc_html_e( 'Quotation Number', 'victoria-erp-connector' ); ?>
        </label>

        <div class="vec-quote-tracker__field-group">
            <input type="text" id="vec_quote_id" name="vec_quote_id" value="<?php echo esc_attr( $quote_id ); ?>" class="vec-quote-tracker__input" placeholder="<?php esc_attr_e( 'Enter quote number', 'victoria-erp-connector' ); ?>" />
            <button type="submit" name="vec_quote_tracker_submit" class="vec-quote-tracker__button button button-primary">
                <?php esc_html_e( 'Track Quote', 'victoria-erp-connector' ); ?>
            </button>
        </div>

        <?php if ( $error !== '' ) : ?>
            <div class="vec-quote-tracker__error"><?php echo esc_html( $error ); ?></div>
        <?php endif; ?>
    </form>

    <?php if ( is_array( $result ) && ! empty( $result ) ) : ?>
        <div class="vec-quote-tracker__result">
            <h3><?php esc_html_e( 'Quote Status', 'victoria-erp-connector' ); ?></h3>
            <div class="vec-quote-tracker__grid">
                <div class="vec-quote-tracker__item">
                    <span class="vec-quote-tracker__label"><?php esc_html_e( 'Status', 'victoria-erp-connector' ); ?></span>
                    <span class="vec-quote-tracker__value"><?php echo esc_html( $result['status'] ?? '-' ); ?></span>
                </div>
                <div class="vec-quote-tracker__item">
                    <span class="vec-quote-tracker__label"><?php esc_html_e( 'Invoice Number', 'victoria-erp-connector' ); ?></span>
                    <span class="vec-quote-tracker__value"><?php echo esc_html( $result['invoice_number'] ?? '-' ); ?></span>
                </div>
                <div class="vec-quote-tracker__item">
                    <span class="vec-quote-tracker__label"><?php esc_html_e( 'Delivery Note', 'victoria-erp-connector' ); ?></span>
                    <span class="vec-quote-tracker__value"><?php echo esc_html( $result['delivery_note'] ?? '-' ); ?></span>
                </div>
            </div>

            <?php if ( isset( $result['details'] ) && is_array( $result['details'] ) ) : ?>
                <div class="vec-quote-tracker__details">
                    <h4><?php esc_html_e( 'Details', 'victoria-erp-connector' ); ?></h4>
                    <pre><?php echo esc_html( wp_json_encode( $result['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
