<?php
namespace Modules\Webinar\Actions;
use CController,
    API,
    CControllerResponseData;

class WebinarView extends CController {
    public function init(): void {
        $this->disableCsrfValidation();
    }
    protected function checkInput(): bool {
        return true;
    }
    protected function checkPermissions(): bool {
        return true;
    }
    protected function doAction(): void {
        $data = API::Item()->get([
            'output' => ['itemid', 'name', 'key_', 'value_type', 'lastvalue'],
            'hostids' => [10084],
            'filter' => ['key_' => 'system.cpu.num']
        ]);
        $response = new CControllerResponseData($data);
        $this->setResponse($response);
    }
}