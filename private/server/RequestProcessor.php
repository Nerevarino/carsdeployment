<?php

require($_SERVER['CARS_CLASSES_PATH'] . 'Roles.php');               //Класс, описывающий роли пользователя, приславшего запрос
require($_SERVER['CARS_CLASSES_PATH'] . 'Car.php');                 //Класс, описывающий структуру данных - автомобиль

class RequestProcessor
{    
    private $memcache;            //Объект соединения с memcache
    private $carsdb;              //Объект соединения с БД        
    private $sql_queries;         //Массив, хранящий sql тексты типовых запросов

    private $extern_interface;    //Массив, хранящий список интерфейсных функций сервера

    private $log;                 //Хранит текст логов обработки запроса
        
    public function __construct()
    {
        $this->initLog();
        $this->describeInterface();
        $this->initMemcache();
        $server = $this->memcache->get('server');       //Создаем этот объект заново или берем существующий из кэша
        if($server) {                                     //Кэш еще есть
            $server->initLog();
            $server->log('got cache <br>');
            $server->receiveExternMemcache($this->memcache);
            $server->respondTo($_GET);
            $server->updateInMemcache();
        } else {                                          //Кэша уже нет или первое обращение, придется все создавать
            $this->log('no cache <br>');
            $this->loadSqlQueries();
            $this->connectDB();            
            $this->respondTo($_GET);
            $this->createInMemcache();
        }
        $this->closeMemcache();
    }
                
    private function respondTo($request)                  //Функция обработки запроса клиента и возвращения ответа клиенту
    {
        $role;                                        //Переменная будет хранить роль обратившегося пользователя
        $role_interface;                              //Набор функций, доступный для данной роли
        $rolename;                                    //Текстовое имя роли
        $data = array();                              //Здесь будут храниться итоговые данные ответа клиенту

        session_start();                              //Создаем или возобновляем существующую сессию
        if(isset($_SESSION['role'])) {                //Если пользователю уже выделена роль
            $role = $_SESSION['role'];
        } else {
            $role = Roles::visitor;
            $_SESSION['role'] = $role;
        }
        $data['rolename'] = Roles::getNameFor($role);
        $role_interface = Roles::getInterfaceFor($role);
        
        if(!isset($request['func'])) {                    //Проверяем наличие параметра func в запросе
            header('HTTP/1.0 403 Forbidden');               //Если его нет, то запрос нелигитимен, возвращаем клиенту код 403
            return;
        }
        $funcname = $request['func'];                     //Получаем имя запрашиваемой функции через этот параметр
        if(!isset($role_interface[$funcname])) {  //Проверяем наличие запрошенной функции в списке интерфейса
            header('HTTP/1.0 403 Forbidden');               //Если ее нет, то запрос нелигитимен, возвращаем клиенту код 403
            return;            
        }
        $processfunc = $role_interface[$funcname];  //Выбираем динамически метод этого объекта на основе имени запрашиваемой функции
        $data[$funcname] = $this->$processfunc($request);                        //Обрабатываем дальше этим методом запрос
        echo json_encode($data);
    }

    private function connectDB()                  //Функция установления соединения с БД
    {
        $dbhost = $_SERVER['CARS_DB_HOST'];
        $dbname = $_SERVER['CARS_DB_NAME'];
        $dbuser = $_SERVER['CARS_DB_USER'];
        $dbuserpassword = $_SERVER['CARS_DB_USER_PASSWORD'];
        $destination="pgsql:dbname={$dbname};host={$dbhost}";
        
        $this->carsdb = new PDO($destination, $dbuser, $dbuserpassword) or die("ERROR: can't connect to database!");
    }

    private function loadSqlQueries()             //Функция загрузки sql текстов типовых запросов
    {
        $this->sql_queries = array();
        $this->sql_queries['cars-list'] = file_get_contents($_SERVER['CARS_LIST_QUERY']);
        $this->sql_queries['car-update'] = file_get_contents($_SERVER['CAR_UPDATE_QUERY']);        
    }

    private function initMemcache()       //Подготовка работы с memcache
    {        
        $memhost = $_SERVER['MEMCACHE_HOST'];
        $memport = $_SERVER['MEMCACHE_PORT'];
        $this->memcache = memcache_connect($memhost, $memport) or die("ERROR: can't connect to memcache server");
    }

    private function closeMemcache()
    {
        $this->memcache->close();
    }

    private function updateInMemcache()         //Пересохраняем этот объект в memcache в его ячейке
    {
        $this->memcache->set('server', $this, false, 10);
    }

    private function createInMemcache()         //Сохранение этого объекта в memcache с выделением под него ячейки
    {
        $this->memcache->add('server', $this, false, 10);   
    }

    private function receiveExternMemcache($memcache)
    {
        $this->memcache = $memcache;
    }

    private function describeInterface()
    {
        $this->extern_interface = array();
        $this->extern_interface['carslist'] = 'listCars';
        $this->extern_interface['carupdate'] = 'updateCar';        
    }

    private function listCars($request)
    {
        
        //Вывод json списка машин
        $prepared_cars_list = $this->carsdb->prepare($this->sql_queries['cars-list']);
        $prepared_cars_list->execute();
        $cars = $prepared_cars_list->fetchAll(PDO::FETCH_CLASS, "Car");
        return $cars;
    }

    private function updateCar($request)
    {
        $prepared_car_update = $this->carsdb->prepare($this->sql_queries['car-update']);
        $prepared_car_update->execute([$request['power'], $request['location'], $request['id']]);
        return "OK!";
    }

    private function initLog()
    {
        $this->log = '';
        $this->log .= "Start<br>";
    }

    private function log($event)
    {
        $this->log .= "{$event}<br>";
    }
    
    public function __sleep()           //вызывается перед сериализацией этого объекта при сохранении в memcache
    {
        //Рвем связь с базой и возвращаем список компонентов этого объекта, подлежащих сериализации
        unset($this->carsdb);
        return array('sql_queries', 'extern_interface');
    }

    public function __wakeup()          //вызывается при десериализации этого объекта при доставании из memcache
    {
        $this->connectDB();    //Восстанавливаем связь с базой
    }
    
};
