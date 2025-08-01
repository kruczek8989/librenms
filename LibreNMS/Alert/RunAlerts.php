<?php

/*
 * RunAlerts.php
 *
 * Copyright (C) 2014 Daniel Preussker <f0o@devilcode.org>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * Original Code:
 * @author Daniel Preussker <f0o@devilcode.org>
 * @copyright 2014 f0o, LibreNMS
 * @license GPL
 * @package LibreNMS
 * @subpackage Alerts
 *
 * Modified by:
 * @author Heath Barnhart <barnhart@kanren.net>
 *
 */

namespace LibreNMS\Alert;

use App\Facades\DeviceCache;
use App\Facades\LibrenmsConfig;
use App\Facades\Rrd;
use App\Models\AlertTransport;
use App\Models\Eventlog;
use LibreNMS\Alerting\QueryBuilderParser;
use LibreNMS\Enum\AlertState;
use LibreNMS\Enum\MaintenanceStatus;
use LibreNMS\Enum\Severity;
use LibreNMS\Exceptions\AlertTransportDeliveryException;
use LibreNMS\Polling\ConnectivityHelper;
use LibreNMS\Util\Number;
use LibreNMS\Util\Time;

class RunAlerts
{
    /**
     * Populate variables
     *
     * @param  string  $txt  Text with variables
     * @param  bool  $wrap  Wrap variable for text-usage (default: true)
     * @return string
     */
    public function populate($txt, $wrap = true)
    {
        preg_match_all('/%([\w\.]+)/', $txt, $m);
        foreach ($m[1] as $tmp) {
            $orig = $tmp;
            $rep = false;
            if ($tmp == 'key' || $tmp == 'value') {
                $rep = '$' . $tmp;
            } else {
                if (strstr($tmp, '.')) {
                    $tmp = explode('.', $tmp, 2);
                    $pre = '$' . $tmp[0];
                    $tmp = $tmp[1];
                } else {
                    $pre = '$obj';
                }

                $rep = $pre . "['" . str_replace('.', "']['", $tmp) . "']";
                if ($wrap) {
                    $rep = '{' . $rep . '}';
                }
            }

            $txt = str_replace('%' . $orig, $rep, $txt);
        }

        return $txt;
    }

    /**
     * Describe Alert
     *
     * @param  array  $alert  Alert-Result from DB
     * @return array|bool|string
     */
    public function describeAlert($alert)
    {
        $obj = [];
        $i = 0;
        $device = DeviceCache::get($alert['device_id']);

        $obj['hostname'] = $device->hostname;
        $obj['sysName'] = $device->sysName;
        $obj['display'] = $device->displayName();
        $obj['sysDescr'] = $device->sysDescr;
        $obj['sysContact'] = $device->sysContact;
        $obj['os'] = $device->os;
        $obj['type'] = $device->type;
        $obj['ip'] = $device->ip;
        $obj['hardware'] = $device->hardware;
        $obj['version'] = $device->version;
        $obj['serial'] = $device->serial;
        $obj['features'] = $device->features;
        $obj['location'] = (string) $device->location;
        $obj['uptime'] = $device->uptime;
        $obj['uptime_short'] = Time::formatInterval($device->uptime, true);
        $obj['uptime_long'] = Time::formatInterval($device->uptime);
        $obj['description'] = $device->purpose;
        $obj['notes'] = $device->notes;
        $obj['alert_notes'] = $alert['note'];
        $obj['device_id'] = $device->device_id;
        $obj['rule_id'] = $alert['rule_id'];
        $obj['id'] = $alert['id'];
        $obj['proc'] = $alert['proc'];
        $obj['status'] = $device->status;
        $obj['status_reason'] = $device->status_reason;
        if ((new ConnectivityHelper($device))->canPing()) {
            $last_ping = Rrd::lastUpdate(Rrd::name($device->hostname, 'icmp-perf'));
            if ($last_ping) {
                $obj['ping_timestamp'] = $last_ping->timestamp;
                $obj['ping_loss'] = Number::calculatePercent($last_ping->get('xmt') - $last_ping->get('rcv'), $last_ping->get('xmt'));
                $obj['ping_min'] = $last_ping->get('min');
                $obj['ping_max'] = $last_ping->get('max');
                $obj['ping_avg'] = $last_ping->get('avg');
                $obj['debug'] = 'unsupported';
            }
        }
        $extra = $alert['details'];

        $tpl = new Template;
        $template = $tpl->getTemplate($obj);

        if ($alert['state'] >= AlertState::ACTIVE) {
            $obj['title'] = $template->title ?: 'Alert for device ' . $obj['display'] . ' - ' . $alert['name'];
            if ($alert['state'] == AlertState::ACKNOWLEDGED) {
                $obj['title'] .= ' Has been acknowledged';
            } elseif ($alert['state'] == AlertState::WORSE) {
                $obj['title'] .= ' Has worsened';
            } elseif ($alert['state'] == AlertState::BETTER) {
                $obj['title'] .= ' Has improved';
            } elseif ($alert['state'] == AlertState::CHANGED) {
                $obj['title'] .= ' changed';
            }

            foreach ($extra['rule'] as $incident) {
                $i++;
                $obj['faults'][$i] = $incident;
                $obj['faults'][$i]['string'] = null;
                foreach ($incident as $k => $v) {
                    if (! empty($v) && $k != 'device_id' && (stristr($k, 'id') || stristr($k, 'desc') || stristr($k, 'msg')) && substr_count($k, '_') <= 1) {
                        $obj['faults'][$i]['string'] .= $k . ' = ' . $v . '; ';
                    }
                }
            }
            $obj['elapsed'] = Time::formatInterval(time() - strtotime($alert['time_logged']), true) ?: 'none';
            if (! empty($extra['diff'])) {
                $obj['diff'] = $extra['diff'];
            }
        } elseif ($alert['state'] == AlertState::RECOVERED) {
            // Alert is now cleared
            $id = dbFetchRow('SELECT alert_log.id,alert_log.time_logged,alert_log.details FROM alert_log WHERE alert_log.state != ? && alert_log.state != ? && alert_log.rule_id = ? && alert_log.device_id = ? && alert_log.id < ? ORDER BY id DESC LIMIT 1', [AlertState::ACKNOWLEDGED, AlertState::RECOVERED, $alert['rule_id'], $alert['device_id'], $alert['id']]);
            if (empty($id['id'])) {
                return false;
            }

            $extra = [];
            if (! empty($id['details'])) {
                $extra = json_decode(gzuncompress($id['details']), true);
            }

            // Reset count to 0 so alerts will continue
            $extra['count'] = 0;
            dbUpdate(['details' => gzcompress(json_encode($id['details']), 9)], 'alert_log', 'id = ?', [$alert['id']]);

            $obj['title'] = $template->title_rec ?: 'Device ' . $obj['display'] . ' recovered from ' . ($alert['name'] ?: $alert['rule']);
            $obj['elapsed'] = Time::formatInterval(strtotime($alert['time_logged']) - strtotime($id['time_logged']), true) ?: 'none';
            $obj['id'] = $id['id'];
            foreach ($extra['rule'] as $incident) {
                $i++;
                $obj['faults'][$i] = $incident;
                $obj['faults'][$i]['string'] = '';
                foreach ($incident as $k => $v) {
                    if (! empty($v) && $k != 'device_id' && (stristr($k, 'id') || stristr($k, 'desc') || stristr($k, 'msg')) && substr_count($k, '_') <= 1) {
                        $obj['faults'][$i]['string'] .= $k . ' => ' . $v . '; ';
                    }
                }
            }
        } else {
            return 'Unknown State';
        }//end if
        $obj['builder'] = $alert['builder'];
        $obj['uid'] = $alert['id'];
        $obj['alert_id'] = $alert['alert_id'];
        $obj['severity'] = $alert['severity'];
        $obj['rule'] = $alert['builder']; //Backwards compatibility for old rule
        $obj['name'] = $alert['name'];
        $obj['timestamp'] = $alert['time_logged'];
        $obj['contacts'] = $extra['contacts'];
        $obj['state'] = $alert['state'];
        $obj['alerted'] = $alert['alerted'];
        $obj['template'] = $template;

        return $obj;
    }

    public function clearStaleAlerts()
    {
        $sql = 'SELECT `alerts`.`id` AS `alert_id`, `devices`.`hostname` AS `hostname` FROM `alerts` LEFT JOIN `devices` ON `alerts`.`device_id`=`devices`.`device_id`  RIGHT JOIN `alert_rules` ON `alerts`.`rule_id`=`alert_rules`.`id` WHERE `alerts`.`state`!=' . AlertState::CLEAR . ' AND `devices`.`hostname` IS NULL';
        foreach (dbFetchRows($sql) as $alert) {
            if (empty($alert['hostname']) && isset($alert['alert_id'])) {
                dbDelete('alerts', '`id` = ?', [$alert['alert_id']]);
                echo "Stale-alert: #{$alert['alert_id']}" . PHP_EOL;
            }
        }
    }

    /**
     * Re-Validate Rule-Mappings
     *
     * @param  int  $device_id  Device-ID
     * @param  int  $rule  Rule-ID
     * @return bool
     */
    public function isRuleValid($device_id, $rule)
    {
        global $rulescache;
        if (empty($rulescache[$device_id]) || ! isset($rulescache[$device_id])) {
            foreach (AlertUtil::getRules($device_id) as $chk) {
                $rulescache[$device_id][$chk['id']] = true;
            }
        }

        if ($rulescache[$device_id][$rule] === true) {
            return true;
        }

        return false;
    }

    /**
     * Issue Alert-Object
     *
     * @param  array  $alert
     * @return bool
     */
    public function issueAlert($alert)
    {
        if (LibrenmsConfig::get('alert.fixed-contacts') == false) {
            if (empty($alert['query'])) {
                $alert['query'] = QueryBuilderParser::fromJson($alert['builder'])->toSql();
            }
            $sql = $alert['query'];
            $qry = dbFetchRows($sql, [$alert['device_id']]);
            $alert['details']['contacts'] = AlertUtil::getContacts($qry);
        }

        $obj = $this->describeAlert($alert);
        if (is_array($obj)) {
            echo 'Issuing Alert-UID #' . $alert['id'] . '/' . $alert['state'] . ':' . PHP_EOL;
            if ($alert['state'] != AlertState::ACKNOWLEDGED || LibrenmsConfig::get('alert.acknowledged') === true) {
                $this->extTransports($obj);
            }
            echo "\r\n";
        }

        return true;
    }

    /**
     * Issue ACK notification
     *
     * @return void
     */
    public function runAcks()
    {
        foreach ($this->loadAlerts('alerts.state = ' . AlertState::ACKNOWLEDGED . ' && alerts.open = ' . AlertState::ACTIVE) as $alert) {
            $rextra = json_decode($alert['extra'], true);
            if (! isset($rextra['acknowledgement'])) {
                // backwards compatibility check
                $rextra['acknowledgement'] = true;
            }

            if ($rextra['acknowledgement']) {
                // Rule is set to send an acknowledgement alert
                $this->issueAlert($alert);
                dbUpdate(['open' => AlertState::CLEAR], 'alerts', 'rule_id = ? && device_id = ?', [$alert['rule_id'], $alert['device_id']]);
            }
        }
    }

    /**
     * Run Follow-Up alerts
     *
     * @return void
     */
    public function runFollowUp()
    {
        foreach ($this->loadAlerts('alerts.state > ' . AlertState::CLEAR . ' && alerts.open = 0') as $alert) {
            if ($alert['state'] != AlertState::ACKNOWLEDGED || ($alert['info']['until_clear'] === false)) {
                $rextra = json_decode($alert['extra'], true);
                if ($rextra['invert']) {
                    continue;
                }

                if (empty($alert['query'])) {
                    $alert['query'] = QueryBuilderParser::fromJson($alert['builder'])->toSql();
                }
                $chk = dbFetchRows($alert['query'], [$alert['device_id']]);
                //make sure we can json_encode all the datas later
                $current_alert_count = count($chk);
                for ($i = 0; $i < $current_alert_count; $i++) {
                    if (isset($chk[$i]['ip'])) {
                        $chk[$i]['ip'] = inet6_ntop($chk[$i]['ip']);
                    }
                }
                $alert['details']['rule'] ??= []; // if details.rule is missing, set it to an empty array
                $ret = 'Alert #' . $alert['id'];
                $state = AlertState::CLEAR;

                // Get the added and resolved items
                [$added_diff, $resolved_diff] = $this->diffBetweenFaults($alert['details']['rule'], $chk);
                $previous_alert_count = count($alert['details']['rule']);

                if (! empty($added_diff) && ! empty($resolved_diff)) {
                    $ret .= ' Changed';
                    $state = AlertState::CHANGED;
                    $alert['details']['diff'] = ['added' => $added_diff, 'resolved' => $resolved_diff];
                } elseif (! empty($added_diff)) {
                    $ret .= ' Worse';
                    $state = AlertState::WORSE;
                    $alert['details']['diff'] = ['added' => $added_diff];
                } elseif (! empty($resolved_diff)) {
                    $ret .= ' Better';
                    $state = AlertState::BETTER;
                    $alert['details']['diff'] = ['resolved' => $resolved_diff];
                    // Failsafe if the diff didn't return any results
                } elseif ($current_alert_count > $previous_alert_count) {
                    $ret .= ' Worse';
                    $state = AlertState::WORSE;
                    Eventlog::log('Alert got worse but the diff was not, ensure that a "id" or "_id" field is available for rule ' . $alert['name'], $alert['device_id'], 'alert', Severity::Warning);
                    // Failsafe if the diff didn't return any results
                } elseif ($current_alert_count < $previous_alert_count) {
                    $ret .= ' Better';
                    $state = AlertState::BETTER;
                    Eventlog::log('Alert got better but the diff was not, ensure that a "id" or "_id" field is available for rule ' . $alert['name'], $alert['device_id'], 'alert', Severity::Warning);
                }

                if ($state > AlertState::CLEAR && $current_alert_count > 0) {
                    $alert['details']['rule'] = $chk;
                    if (dbInsert([
                        'state' => $state,
                        'device_id' => $alert['device_id'],
                        'rule_id' => $alert['rule_id'],
                        'details' => gzcompress(json_encode($alert['details']), 9),
                    ], 'alert_log')) {
                        dbUpdate(['state' => $state, 'open' => 1, 'alerted' => 1], 'alerts', 'rule_id = ? && device_id = ?', [$alert['rule_id'], $alert['device_id']]);
                    }

                    echo $ret . ' (' . $previous_alert_count . '/' . $current_alert_count . ")\r\n";
                }
            }
        }
    }

    /**
     * Extract the fields that are used to identify the elements in the array of a "fault"
     *
     * @param  array  $element
     * @return array
     */
    private function extractIdFieldsForFault($element)
    {
        return array_filter(array_keys($element), function ($key) {
            // Exclude location_id as it is not relevant for the comparison
            return ($key === 'id' || strpos($key, '_id')) !== false && $key !== 'location_id';
        });
    }

    /**
     * Generate a comparison key for an element based on the fields that identify it for a "fault"
     *
     * @param  array  $element
     * @param  array  $idFields
     * @return string
     */
    private function generateComparisonKeyForFault($element, $idFields)
    {
        $keyParts = [];
        foreach ($idFields as $field) {
            $keyParts[] = isset($element[$field]) ? $element[$field] : '';
        }

        return implode('|', $keyParts);
    }

    /**
     * Find new elements in the array for faults
     * PHP array_diff is not working well for it
     *
     * @param  array  $array1
     * @param  array  $array2
     * @return array [$added, $removed]
     */
    private function diffBetweenFaults($array1, $array2)
    {
        $array1_keys = [];
        $added_elements = [];
        $removed_elements = [];

        // Create associative array for quick lookup of $array1 elements
        foreach ($array1 as $element1) {
            $element1_ids = $this->extractIdFieldsForFault($element1);
            $element1_key = $this->generateComparisonKeyForFault($element1, $element1_ids);
            $array1_keys[$element1_key] = $element1;
        }

        // Iterate through $array2 and determine added elements
        foreach ($array2 as $element2) {
            $element2_ids = $this->extractIdFieldsForFault($element2);
            $element2_key = $this->generateComparisonKeyForFault($element2, $element2_ids);

            if (! isset($array1_keys[$element2_key])) {
                $added_elements[] = $element2;
            } else {
                // Remove matched elements
                unset($array1_keys[$element2_key]);
            }
        }

        // Remaining elements in $array1_keys are the removed elements
        $removed_elements = array_values($array1_keys);

        return [$added_elements, $removed_elements];
    }

    public function loadAlerts($where)
    {
        $alerts = [];
        foreach (dbFetchRows("SELECT alerts.id, alerts.alerted, alerts.device_id, alerts.rule_id, alerts.state, alerts.note, alerts.info FROM alerts WHERE $where") as $alert_status) {
            $alert = dbFetchRow(
                'SELECT alert_log.id,alert_log.rule_id,alert_log.device_id,alert_log.state,alert_log.details,alert_log.time_logged,alert_rules.severity,alert_rules.extra,alert_rules.name,alert_rules.query,alert_rules.builder,alert_rules.proc FROM alert_log,alert_rules WHERE alert_log.rule_id = alert_rules.id && alert_log.device_id = ? && alert_log.rule_id = ? && alert_rules.disabled = 0 ORDER BY alert_log.id DESC LIMIT 1',
                [$alert_status['device_id'], $alert_status['rule_id']]
            );

            $alert['alert_id'] = $alert_status['id'];

            if (empty($alert['rule_id']) || ! $this->isRuleValid($alert_status['device_id'], $alert_status['rule_id'])) {
                echo 'Stale-Rule: #' . $alert_status['rule_id'] . '/' . $alert_status['device_id'] . "\r\n";
                // Alert-Rule does not exist anymore, let's remove the alert-state.
                dbDelete('alerts', 'rule_id = ? && device_id = ?', [$alert_status['rule_id'], $alert_status['device_id']]);
            } else {
                $alert['state'] = $alert_status['state'];
                $alert['alerted'] = $alert_status['alerted'];
                $alert['note'] = $alert_status['note'];
                if (! empty($alert['details'])) {
                    $alert['details'] = json_decode(gzuncompress($alert['details']), true);
                }
                $alert['info'] = json_decode($alert_status['info'], true);
                $alerts[] = $alert;
            }
        }

        return $alerts;
    }

    /**
     * Run all alerts
     *
     * @return void
     */
    public function runAlerts()
    {
        foreach ($this->loadAlerts('alerts.state != ' . AlertState::ACKNOWLEDGED . ' && alerts.open = 1') as $alert) {
            $noiss = false;
            $noacc = false;
            $updet = false;
            $rextra = json_decode($alert['extra'], true);
            if (! isset($rextra['recovery'])) {
                // backwards compatibility check
                $rextra['recovery'] = true;
            }

            if (! isset($alert['details']['count'])) {
                // make sure count is set for below code, in legacy code null would get type juggled to 0
                $alert['details']['count'] = 0;
            }

            $chk = dbFetchRow('SELECT alerts.alerted,devices.ignore,devices.disabled FROM alerts,devices WHERE alerts.device_id = ? && devices.device_id = alerts.device_id && alerts.rule_id = ?', [$alert['device_id'], $alert['rule_id']]);

            if ($chk['alerted'] == $alert['state']) {
                $noiss = true;
            }

            $tolerence_window = LibrenmsConfig::get('alert.tolerance_window');
            if (! empty($rextra['count']) && empty($rextra['interval'])) {
                // This check below is for compat-reasons
                if (! empty($rextra['delay']) && $alert['state'] != AlertState::RECOVERED) {
                    if ((time() - strtotime($alert['time_logged']) + $tolerence_window) < $rextra['delay'] || (! empty($alert['details']['delay']) && (time() - $alert['details']['delay'] + $tolerence_window) < $rextra['delay'])) {
                        continue;
                    } else {
                        $alert['details']['delay'] = time();
                        $updet = true;
                    }
                }

                if ($alert['state'] == AlertState::ACTIVE && ! empty($rextra['count']) && ($rextra['count'] == -1 || $alert['details']['count']++ < $rextra['count'])) {
                    // We don't want -1 alert rule count alarms to get muted because of the current alert count
                    if ($alert['details']['count'] < $rextra['count'] || $rextra['count'] == -1) {
                        $noacc = true;
                    }

                    $updet = true;
                    $noiss = false;
                }
            } else {
                // This is the new way
                if (! empty($rextra['delay']) && (time() - strtotime($alert['time_logged']) + $tolerence_window) < $rextra['delay'] && $alert['state'] != AlertState::RECOVERED) {
                    continue;
                }

                if (! empty($rextra['interval'])) {
                    if (! empty($alert['details']['interval']) && (time() - $alert['details']['interval'] + $tolerence_window) < $rextra['interval']) {
                        continue;
                    } else {
                        $alert['details']['interval'] = time();
                        $updet = true;
                    }
                }

                if (in_array($alert['state'], [AlertState::ACTIVE, AlertState::WORSE, AlertState::BETTER, AlertState::CHANGED]) && ! empty($rextra['count']) && ($rextra['count'] == -1 || $alert['details']['count']++ < $rextra['count'])) {
                    // We don't want -1 alert rule count alarms to get muted because of the current alert count
                    if ($alert['details']['count'] < $rextra['count'] || $rextra['count'] == -1) {
                        $noacc = true;
                    }

                    $updet = true;
                    $noiss = false;
                }
            }
            if ($chk['ignore'] == 1 || $chk['disabled'] == 1) {
                $noiss = true;
                $updet = false;
                $noacc = false;
            }

            $maintenance_status = AlertUtil::getMaintenanceStatus($alert['device_id']);
            // Do not send alert notifications for these types of scheduled maintenance
            if ($maintenance_status == MaintenanceStatus::MUTE_ALERTS) {
                $noiss = true;
            }

            // If alert rule checks are to be skipped, ensure that this alert is
            // not to be handled again by this method again (by changing open to 0 later)
            if ($maintenance_status == MaintenanceStatus::SKIP_ALERTS) {
                $noiss = true;
                $noacc = true;
            }

            if ($updet) {
                dbUpdate(['details' => gzcompress(json_encode($alert['details']), 9)], 'alert_log', 'id = ?', [$alert['id']]);
            }

            if (! empty($rextra['mute'])) {
                echo 'Muted Alert-UID #' . $alert['id'] . "\r\n";
                $noiss = true;
            }

            if ($this->isParentDown($alert['device_id'])) {
                $noiss = true;
                Eventlog::log('Skipped alerts because all parent devices are down', $alert['device_id'], 'alert', Severity::Ok);
            }

            if ($alert['state'] == AlertState::RECOVERED && $rextra['recovery'] == false) {
                // Rule is set to not send a recovery alert
                $noiss = true;
            }

            if (! $noiss) {
                $this->issueAlert($alert);
                dbUpdate(['alerted' => $alert['state']], 'alerts', 'rule_id = ? && device_id = ?', [$alert['rule_id'], $alert['device_id']]);
            }

            if (! $noacc) {
                dbUpdate(['open' => 0], 'alerts', 'rule_id = ? && device_id = ?', [$alert['rule_id'], $alert['device_id']]);
            }
        }
    }

    /**
     * Run external transports
     *
     * @param  array  $obj  Alert-Array
     * @return void
     */
    public function extTransports($obj)
    {
        $type = new Template;

        // If alert transport mapping exists, override the default transports
        $transport_maps = AlertUtil::getAlertTransports($obj['alert_id']);

        if (! $transport_maps) {
            $transport_maps = AlertUtil::getDefaultAlertTransports();
        }

        // alerting for default contacts, etc
        if (LibrenmsConfig::get('alert.transports.mail') === true && ! empty($obj['contacts'])) {
            $transport_maps[] = [
                'transport_id' => null,
                'transport_type' => 'mail',
                'transport_name' => 'Default Mail',
            ];
        }

        foreach ($transport_maps as $item) {
            $class = Transport::getClass($item['transport_type']);
            if (class_exists($class)) {
                //FIXME remove Deprecated transport
                $transport_title = "Transport {$item['transport_type']}";
                $obj['transport'] = $item['transport_type'];
                $obj['transport_name'] = $item['transport_name'];
                $obj['alert'] = new AlertData($obj);
                $obj['title'] = $type->getTitle($obj);
                $obj['alert']['title'] = $obj['title'];
                $obj['msg'] = $type->getBody($obj);
                c_echo(" :: $transport_title => ");
                try {
                    $instance = new $class(AlertTransport::find($item['transport_id']));
                    $tmp = $instance->deliverAlert($obj);
                    $this->alertLog($tmp, $obj, $obj['transport']);
                } catch (AlertTransportDeliveryException $e) {
                    Eventlog::log($e->getTraceAsString() . PHP_EOL . $e->getMessage(), $obj['device_id'], 'alert', Severity::Error);
                    $this->alertLog($e->getMessage(), $obj, $obj['transport']);
                } catch (\Exception $e) {
                    $this->alertLog($e, $obj, $obj['transport']);
                }
                unset($instance);
                echo PHP_EOL;
            }
        }

        if (count($transport_maps) === 0) {
            echo 'No configured transports';
        }
    }

    // Log alert event
    public function alertLog($result, $obj, $transport)
    {
        $prefix = [
            AlertState::RECOVERED => 'recovery',
            AlertState::ACTIVE => $obj['severity'] . ' alert',
            AlertState::ACKNOWLEDGED => 'acknowledgment',
            AlertState::WORSE => 'worsened',
            AlertState::BETTER => 'improved',
            AlertState::CHANGED => 'changed',
        ];

        $severity = match ($obj['state']) {
            AlertState::RECOVERED => Severity::Ok,
            AlertState::ACTIVE => Severity::tryFrom((int) $obj['severity']) ?? Severity::Unknown,
            AlertState::ACKNOWLEDGED => Severity::Notice,
            default => Severity::Unknown,
        };

        if ($result === true) {
            echo 'OK';
            Eventlog::log('Issued ' . $prefix[$obj['state']] . " for rule '" . $obj['name'] . "' to transport '" . $transport . "'", $obj['device_id'], 'alert', $severity);
        } elseif ($result === false) {
            echo 'ERROR';
            Eventlog::log('Could not issue ' . $prefix[$obj['state']] . " for rule '" . $obj['name'] . "' to transport '" . $transport . "'", $obj['device_id'], null, Severity::Error);
        } else {
            echo "ERROR: $result\r\n";
            Eventlog::log('Could not issue ' . $prefix[$obj['state']] . " for rule '" . $obj['name'] . "' to transport '" . $transport . "' Error: " . $result, $obj['device_id'], 'error', Severity::Error);
        }
    }

    /**
     * Check if a device's all parent are down
     * Returns true if all parents are down
     *
     * @param  int  $device  Device-ID
     * @return bool
     */
    public function isParentDown($device)
    {
        $parent_count = dbFetchCell('SELECT count(*) from `device_relationships` WHERE `child_device_id` = ?', [$device]);
        if (! $parent_count) {
            return false;
        }

        $down_parent_count = dbFetchCell("SELECT count(*) from devices as d LEFT JOIN devices_attribs as a ON d.device_id=a.device_id LEFT JOIN device_relationships as r ON d.device_id=r.parent_device_id WHERE d.status=0 AND d.ignore=0 AND d.disabled=0 AND r.child_device_id=? AND (d.status_reason='icmp' OR (a.attrib_type='override_icmp_disable' AND a.attrib_value=true))", [$device]);
        if ($down_parent_count == $parent_count) {
            return true;
        }

        return false;
    }
}
