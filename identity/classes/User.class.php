<?php
/*
    This file is part of Symbiose Community Edition <https://github.com/yesbabylon/symbiose>
    Some Rights Reserved, Yesbabylon SRL, 2020-2021
    Licensed under GNU AGPL 3 license <http://www.gnu.org/licenses/>
*/
namespace identity;

class User extends \core\User {

    public static function getName() {
        return 'User';
    }

    public static function getColumns() {
        return [

            'name' => [
                'type'              => 'computed',
                'function'          => 'calcName',
                'result_type'       => 'string',
                'store'             => true,
                'description'       => 'The display name of the user.'
            ],

            'identity_id' => [
                'type'              => 'many2one',
                'foreign_object'    => 'identity\Identity',
                'domain'            => ['type', '=', 'I'],
                'description'       => 'The contact related to the user.',
                'onupdate'          => 'onupdateIdentity'
            ],

            'setting_values_ids' => [
                'type'              => 'one2many',
                'foreign_object'    => 'core\setting\SettingValue',
                'foreign_field'     => 'user_id',
                'description'       => 'List of settings that relate to the user.'
            ],

            /* the organisation the user is part of (multi-company support) */
            'organisation_id' => [
                'type'              => 'many2one',
                'foreign_object'    => 'identity\Identity',
                'description'       => "The organisation the user relates to.",
                'default'           => 1
            ]


        ];
    }

    public static function calcName($om, $oids, $lang) {
        $result = [];
        $users = $om->read(self::getType(), $oids, ['login', 'identity_id.name']);
        foreach($users as $oid => $odata) {
            if(isset($odata['identity_id.name']) && strlen($odata['identity_id.name']) ) {
                $result[$oid] = $odata['identity_id.name'];
            }
            else {
                $result[$oid] = $odata['login'];
            }
        }
        return $result;
    }


    public static function onupdateIdentity($om, $oids, $values, $lang) {
        // force re-compute the name
        $om->write(self::getType(), $oids, ['name' =>  null], $lang);
    }
}