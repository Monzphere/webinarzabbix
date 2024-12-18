<?php

namespace Modules\LocationMap\Actions;

use CController,
    CControllerResponseData,
    CControllerResponseFatal,
    CRoleHelper,
    API;

class MapView extends CController {
    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $fields = [
            'groupids' => 'string'
        ];

        $ret = $this->validateInput($fields);

        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }

        return $ret;
    }

    protected function checkPermissions(): bool {
        return $this->checkAccess(CRoleHelper::UI_MONITORING_HOSTS);
    }

    protected function doAction(): void {
        $data = [
            'hosts' => [],
            'stats' => [
                'total_hosts' => 0,
                'hosts_with_coords' => 0,
                'hosts_without_coords' => 0,
                'hosts_enabled' => 0,
                'hosts_disabled' => 0,
                'problems' => [
                    0 => 0, // Not classified
                    1 => 0, // Information
                    2 => 0, // Warning
                    3 => 0, // Average
                    4 => 0, // High
                    5 => 0  // Disaster
                ],
                'problems_total' => 0,
                'problems_unack' => 0,
                'last_update' => date('Y-m-d H:i:s')
            ],
            'error' => null
        ];

        try {
            $groupids = [];
            
            if ($this->hasInput('groupids')) {
                $groupids = $this->getInput('groupids', []);
                if (!is_array($groupids)) {
                    $groupids = explode(',', $groupids);
                }
                $groupids = array_map('intval', array_filter($groupids));
                
                if (!empty($groupids)) {
                    // Busca todos os hosts do grupo
                    $all_hosts = API::Host()->get([
                        'output' => ['hostid', 'host', 'name', 'status'],
                        'groupids' => $groupids,
                        'selectInventory' => ['location_lat', 'location_lon'],
                        'selectInterfaces' => ['ip'],
                    ]);

                    $data['stats']['total_hosts'] = count($all_hosts);

                    // Filtra hosts com coordenadas
                    $data['hosts'] = array_values(array_filter($all_hosts, function($host) {
                        return !empty($host['inventory']) && 
                               !empty($host['inventory']['location_lat']) && 
                               !empty($host['inventory']['location_lon']);
                    }));

                    $data['stats']['hosts_with_coords'] = count($data['hosts']);

                    // Busca problemas ativos
                    $problems = API::Trigger()->get([
                        'output' => ['triggerid', 'priority', 'description', 'lastchange'],
                        'groupids' => $groupids,
                        'hostids' => array_column($all_hosts, 'hostid'),
                        'monitored' => true,
                        'only_true' => true,  // Apenas triggers em estado de problema
                        'skipDependent' => true,
                        'sortfield' => ['priority', 'lastchange'],
                        'sortorder' => 'DESC',
                        'filter' => [
                            'value' => TRIGGER_VALUE_TRUE,
                            'status' => TRIGGER_STATUS_ENABLED
                        ],
                        'selectHosts' => ['hostid', 'name']
                    ]);

                    error_log('Found problems: ' . print_r($problems, true));

                    // Inicializa contadores
                    foreach (range(0, 5) as $severity) {
                        $data['stats']['problems'][$severity] = 0;
                    }

                    // Conta problemas por severidade
                    if (is_array($problems) && !empty($problems)) {
                        foreach ($problems as $problem) {
                            $severity = (int)$problem['priority'];
                            if (isset($data['stats']['problems'][$severity])) {
                                $data['stats']['problems'][$severity]++;
                            }
                        }
                    }

                    // Atualiza total de problemas
                    $data['stats']['problems_total'] = array_sum($data['stats']['problems']);

                    // Busca problemas não reconhecidos
                    $unack_problems = API::Event()->get([
                        'output' => ['eventid'],
                        'groupids' => $groupids,
                        'hostids' => array_column($all_hosts, 'hostid'),
                        'source' => EVENT_SOURCE_TRIGGERS,
                        'object' => EVENT_OBJECT_TRIGGER,
                        'value' => TRIGGER_VALUE_TRUE,
                        'acknowledged' => false,
                        'suppressed' => false
                    ]);

                    $data['stats']['problems_unack'] = is_array($unack_problems) ? count($unack_problems) : 0;

                    error_log('Problems summary: total=' . $data['stats']['problems_total'] . 
                              ', unack=' . $data['stats']['problems_unack'] . 
                              ', details=' . print_r($data['stats']['problems'], true));

                    if (!empty($data['hosts'])) {
                        foreach ($data['hosts'] as &$host) {
                            // Adiciona o status do host primeiro
                            $host['is_enabled'] = ($host['status'] == HOST_STATUS_MONITORED);
                            
                            // Inicializa os contadores
                            $host['problems'] = 0;
                            $host['max_severity'] = 0;

                            // Busca problemas do host
                            $host_problems = API::Trigger()->get([
                                'output' => ['triggerid', 'priority'],
                                'hostids' => [$host['hostid']],
                                'monitored' => true,
                                'only_true' => true,
                                'skipDependent' => true,
                                'filter' => [
                                    'value' => TRIGGER_VALUE_TRUE,
                                    'status' => TRIGGER_STATUS_ENABLED
                                ]
                            ]);

                            if (!empty($host_problems)) {
                                $host['problems'] = count($host_problems);
                                $max_priority = max(array_column($host_problems, 'priority'));
                                $host['max_severity'] = $max_priority;
                            }

                            error_log('Host status: ' . print_r([
                                'hostid' => $host['hostid'],
                                'name' => $host['name'],
                                'enabled' => $host['is_enabled'],
                                'problems' => $host['problems'],
                                'max_severity' => $host['max_severity'],
                                'status' => $host['status']
                            ], true));
                        }
                        unset($host);
                    }
                }
            }

            if ($this->isAjaxRequest()) {
                $this->sendJsonResponse($data);
                return;
            }

            $response = new CControllerResponseData($data);
            $this->setResponse($response);
        }
        catch (\Exception $e) {
            error_log('Error in MapView::doAction: ' . $e->getMessage());
            $data['error'] = $e->getMessage();
            
            if ($this->isAjaxRequest()) {
                $this->sendJsonResponse(['error' => $e->getMessage()]);
                return;
            }
            
            $response = new CControllerResponseData($data);
            $this->setResponse($response);
        }
    }

    private function getGroupIds(): array {
        $input = $this->getInput('groupids', '');
        
        // Se for string e tiver vírgula, pega apenas o primeiro valor
        if (is_string($input) && strpos($input, ',') !== false) {
            $parts = explode(',', $input);
            return [(int)$parts[0]];
        }
        // Se for array, pega apenas o primeiro elemento
        elseif (is_array($input)) {
            return [reset($input)];
        }
        // Se for string simples
        elseif (!empty($input)) {
            return [(int)$input];
        }
        
        return [];
    }

    protected function isAjaxRequest(): bool {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    protected function sendJsonResponse($data): void {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
} 