<?php
if (class_exists("\Sale\Payment")) {
    try {
        \Sale\Payment::addGateway('\SalePaymentVTB\Gateway');
    } catch (\Exception $e) {
    }
}