<?php

class Roles      //Класс, описывающий роль пользователя, приславшего запрос (с помощью него реализуется прием/отклонение запроса)
{
    const none    =  0;
    const visitor =  1;
    const client  =  2;
    const manager =  3;
    
    const interfaces = [
        [],                           //none
        [                             //visitor
            'carslist'  => 'listCars',
            'carupdate' => 'updateCar'
        ],
        [                             //client
            'carslist' => 'listCars'
        ],
        [                             //manager
            'carslist' => 'listCars'
        ]
    ];

    const names = [
        'none',
        'visitor',
        'client',
        'manager'
    ];

    public static function getInterfaceFor($role)
    {
        return self::interfaces[$role];
    }

    public static function getNameFor($role)
    {
        return self::names[$role];
    }    
};

