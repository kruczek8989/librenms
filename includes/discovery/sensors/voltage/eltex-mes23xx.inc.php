<?php

/*
 * LibreNMS discovery module for Eltex-mes23xx SFP Voltage
 *
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
 * @package    LibreNMS
 * @link       https://www.librenms.org
 *
 * @copyright  2022 Peca Nesovanovic
 *
 * @author     Peca Nesovanovic <peca.nesovanovic@sattrakt.com>
 */
$divisor = 1000000;
$multiplier = 1;

$oids = SnmpQuery::cache()->hideMib()->walk('ELTEX-MES-PHYSICAL-DESCRIPTION-MIB::eltPhdTransceiverThresholdTable')->table(2);
$oids = SnmpQuery::cache()->hideMib()->walk('RADLAN-PHY-MIB::rlPhyTestGetResult')->table(1, $oids);

foreach ($oids as $ifIndex => $data) {
    if (isset($data['rlPhyTestGetResult']['rlPhyTestTableTransceiverSupply'])) {
        $value = $data['rlPhyTestGetResult']['rlPhyTestTableTransceiverSupply'] / $divisor;
        $high_limit = $data['supply']['eltPhdTransceiverThresholdHighAlarm'] / $divisor;
        $high_warn_limit = $data['supply']['eltPhdTransceiverThresholdHighWarning'] / $divisor;
        $low_warn_limit = $data['supply']['eltPhdTransceiverThresholdLowWarning'] / $divisor;
        $low_limit = $data['supply']['eltPhdTransceiverThresholdLowAlarm'] / $divisor;
        $port = PortCache::getByIfIndex($ifIndex, $device['device_id']);
        $descr = $port?->ifName;
        $oid = '.1.3.6.1.4.1.89.90.1.2.1.3.' . $ifIndex . '.6';

        app('sensor-discovery')->discover(new \App\Models\Sensor([
            'poller_type' => 'snmp',
            'sensor_class' => 'voltage',
            'sensor_oid' => $oid,
            'sensor_index' => 'SfpVolt' . $ifIndex,
            'sensor_type' => 'rlPhyTestTableTransceiverSupply',
            'sensor_descr' => 'SfpVolt-' . $descr,
            'sensor_divisor' => $divisor,
            'sensor_multiplier' => $multiplier,
            'sensor_limit_low' => $low_limit,
            'sensor_limit_low_warn' => $low_warn_limit,
            'sensor_limit_warn' => $high_warn_limit,
            'sensor_limit' => $high_limit,
            'sensor_current' => $value,
            'entPhysicalIndex' => $ifIndex,
            'entPhysicalIndex_measured' => 'port',
            'user_func' => null,
            'group' => 'transceiver',
        ]));
    }
}
