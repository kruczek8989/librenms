<?php

/**
 * GlobeController.php
 *
 * -Description-
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
 * @link       https://www.librenms.org
 *
 * @copyright  2018 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace App\Http\Controllers\Widgets;

use App\Facades\LibrenmsConfig;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use LibreNMS\Util\Number;

class GlobeController extends WidgetController
{
    protected string $name = 'globe';

    public function __construct()
    {
        // init defaults we need to check config, so do it in construct
        $this->defaults = [
            'title' => null,
            'markers' => LibrenmsConfig::get('frontpage_globe.markers', 'devices'),
            'region' => LibrenmsConfig::get('frontpage_globe.region', 'world'),
            'resolution' => LibrenmsConfig::get('frontpage_globe.resolution', 'countries'),
            'device_group' => null,
        ];
    }

    /**
     * @param  Request  $request
     * @return View
     */
    public function getView(Request $request): string|View
    {
        $data = $this->getSettings();
        $locations = new Collection();

        $eager_load = $data['markers'] == 'ports' ? ['devices.ports'] : ['devices'];
        $query = Location::hasAccess($request->user())
            ->with($eager_load)
            ->when($data['device_group'], function ($query) use ($data) {
                return $query->inDeviceGroup($data['device_group']);
            });

        /** @var Location $location */
        foreach ($query->get() as $location) {
            $count = 0;
            $up = 0;
            $down_items = new Collection();

            if ($data['markers'] == 'devices') {
                $count = $location->devices->count();
                [$devices_down, $devices_up] = $location->devices->partition(function ($device) {
                    return $device->disabled == 0 && $device->ignore == 0 && $device->status == 0;
                });
                $up = $devices_up->count();
                $down_items = $devices_down->map(function ($device) {
                    return $device->displayName() . ' DOWN';
                });
            } elseif ($data['markers'] == 'ports') {
                foreach ($location->devices as $device) {
                    [$ports_down, $ports_up] = $device->ports->partition(function ($port) {
                        return $port->ifOperStatus != 'up' && $port->ifAdminStatus == 'up';
                    });
                    $count += $device->ports->count();
                    $up += $ports_up->count();
                    $down_items = $ports_down->map(function ($port) use ($device) {
                        return $device->displayName() . '/' . $port->getShortLabel() . ' DOWN';
                    });
                }
            }

            // indicate the number of up items before the itemized down
            $down_items->prepend($up . '&nbsp;' . ucfirst($data['markers']) . '&nbsp;OK');

            if ($count > 0) {
                $locations->push([
                    $location->lat,
                    $location->lng,
                    $location->location,
                    Number::calculatePercent($count - $up, $count), // percent down
                    $count,
                    $down_items->implode(',<br/> '),
                ]);
            }
        }

        $data['locations'] = $locations->toJson();

        return view('widgets.globe', $data);
    }
}
