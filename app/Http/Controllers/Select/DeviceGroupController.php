<?php

/**
 * DeviceGroupController.php
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

namespace App\Http\Controllers\Select;

use App\Models\DeviceGroup;

class DeviceGroupController extends SelectController
{
    protected function rules()
    {
        return [
            'type' => 'nullable|in:static,dynamic',
        ];
    }

    protected function searchFields($request)
    {
        return ['name'];
    }

    protected function baseQuery($request)
    {
        return DeviceGroup::hasAccess($request->user())
            ->when($request->get('type'), fn ($query, $type) => $query->where('type', $type))
            ->select(['id', 'name']);
    }

    /**
     * @param  DeviceGroup  $device_group
     */
    public function formatItem($device_group)
    {
        return [
            'id' => $device_group->id,
            'text' => $device_group->name,
        ];
    }
}
