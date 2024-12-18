<?php
(new CHtmlPage())
    ->setTitle(_('Webinar Monzphere'))
    ->addItem(
        (new CDiv($data[0]['lastvalue']))
            ->addClass('webinar-container')
    )
    ->show();