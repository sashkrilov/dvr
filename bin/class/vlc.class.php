<?php

/**
 * Class vlc_rpc обертка для класса vlc, так как rpc почему то вызывается не с прямыми параметрами, а в виде массива
 */
class vlc_rpc{
    /**
     * @var vlc vlc
     */
    protected $vlc;
    /**
     * @var UserID
     */
    protected $uid;

    /**
     * @param UserID $uid
     */
    public function __construct(UserID $uid) {
        $this->uid = $uid;
        $this->vlc = new vlc($uid, new YesNo(true));
    }

    /**
     * @return string
     */
    public function start() {
        ob_start();
        $this->vlc->start();
        $ret = ob_get_contents();
        ob_end_clean();
        return rawurlencode($ret);
    }

    /**
     * @return string
     */
    public function stop() {
        ob_start();
        $this->vlc->stop();
        $ret = ob_get_contents();
        ob_end_clean();
        return rawurlencode($ret);
    }

    /**
     * @return string
     */
    public function restart() {
        ob_start();
        $this->vlc->restart();
        $ret = ob_get_contents();
        ob_end_clean();
        return rawurlencode($ret);
    }

    /**
     * @return string
     */
    public function status() {
        ob_start();
        $this->vlc->status();
        $ret = ob_get_contents();
        ob_end_clean();
        return  rawurlencode($ret);
    }

    /**
     * @param int $cid
     * @param string $pref
     * @return int
     */
    public function cam_play($cid,$pref) {
        if(!$this->vlc->is_run()){//запущен ли vlc?
            //try to start
            ob_start();
            $this->vlc->start();
            ob_end_clean();
            sleep(1);
        }

        //todo: Если не запустился - лепим какую нибудь ошибку и разбираемся дальше

        $vlm = new cam_control_archive($this->uid,$cid[0],$pref[0]);
        $vlm->play();

        return 1;
    }
    
    //почему то параметры передаются в массиве....
    /**
     * @param $cid
     * @param $pref
     * @return int
     */
    public function cam_stop($cid,$pref) {
        if(!$this->vlc->is_run()) return 0; //запущен ли vlc?

        $vlm = new cam_control_archive($this->uid,$cid[0],$pref[0]);
        $vlm->stop();
        return 1;
    }

    /*
     * управление камерами
     */
    /**
     * @param $cid
     * @param $pref
     */
    public function create_cam($cid,$pref){
        $this->vlc->create_cam($cid[0],$pref[0], new YesNo(false));
    }

    /**
     * @param $cid
     * @param $pref
     */
    public function delete_cam($cid,$pref){
        $this->vlc->delete_cam($cid[0],$pref[0], new YesNo(false));
    }

    /**
     * @param $cid
     * @param $pref
     */
    public function play_cam($cid,$pref){
        $this->vlc->play_cam($cid[0],$pref[0], new YesNo(false));
    }

    /**
     * @param $cid
     * @param $pref
     */
    public function stop_cam($cid,$pref){
        $this->vlc->stop_cam($cid[0],$pref[0]);
    }
}


/**
 * Class vlc
 * @property
 */
class vlc{
    /**
     * @var UserID id пользователя в базе mysql
     */
    protected $uid;
    /**
     * @var array список рабочих дирректорий
     */
    protected $dirs = array();
    /**
     * @var config класс для генерации конфиг файлов
     */
    protected $config;
    /**
     * @var telnet класс для протокола telnet
     */
    protected $telnet;
    /**
     * @var string текстовый конфиг vlm
     */
    protected $vlm = '';
    /**
     * @var Path proc файл
     */
    protected $proc;
    /**
     * @var Path путь к лог файлу
     */
    protected $log = '';
    /**
     * @var Path путь к конфигу ротации логов
     */
    protected $logrotate = '';
    /**
     * @var Port порт для управления по telnet протоколу
     */
    protected $tl_port;
    /**
     * @var Port порт для управления по http протоколу
     */
    protected $ht_port;

    /**
     * @var array список объектов выборки из mysql для данного пользователя
     */
    protected $cams = array();

    /**
     * @var YesNo динамические камеры или нет
     */
    protected $dyn;
    //вообще мы выходим на полностью динамические камеры

    /**
     * @param UserID $uid id пользователя в базе
     * @param YesNo $dyn динамические камеры?
     */
    public function __construct(UserID $uid, YesNo $dyn=null) {
        if(is_null($dyn)) $dyn = new YesNo(true);
        if(!$uid) die($this->error(__LINE__, " пользователь не указан"));
        $this->uid = $uid;
        $this->dyn = $dyn;
        $this->dirs = array(
            //'bin',
            'etc', 'proc', 'rec', 'mtn', 'log', 'img', 'tmp'
            );

        if(!$this->dyn)
            $this->vlm = $this->config->vlm();

        $this->path_vlm = ETC."/$this->uid/$this->uid.vlm";

        $this->proc = PROC."/$this->uid/vlc.pid";
        $this->log  =  LOG."/$this->uid/vlc.log";
        $this->logrotate = ETC."/$this->uid/logrotate.conf";
        $this->ht_port = HTSTART+$this->get_uid();
        $this->tl_port = TLSTART+$this->get_uid();

        $this->telnet = new telnet();
        $this->config = new config($this->uid);

        // если настроек нет, то и не будет камеры в нашем списке
        $q = "select * from cams as c,cam_settings as cs where c.id=cs.cam_id and user_id={$this->uid}";
        $r = mysql_query($q);
        $n = mysql_num_rows($r);
        
        for($i=0;$i<$n;$i++){
            $cam = mysql_fetch_object($r);
            $this->cams[$cam->cam_id] = $cam;
        }

        //примонтировать NAS
        $this->mount();

        $this->create_user_dirs();
    }

    /**
     * примонтировать наш nas
     */
    public function mount(){
        $nas = new nas();
        $nas->mount();
    }

    /**
     * размонтировать наш nas
     */
    public function un_mount(){
        $nas = new nas();
        $nas->un_mount();
    }

    /**
     * @return UserID
     */
    public function get_uid() {
        return $this->uid;
    }

    /**
     * Функция геренации ошибок
     * @param int $line Номер линии в файле __LINE__
     * @param string $text Текст ошибки
     * @return string
     */
    protected function error($line,$text) {
        return 'ERROR: ('.__FILE__.' line:'.$line.') '.$text."\n";
    }

    /**
     * @param CamID $cid
     * @param CamPrefix $pref
     * @param YesNo $debug
     */
    public function create_cam(CamID $cid, CamPrefix $pref, YesNo $debug){

        $cam = $this->cams[$cid->get()];
        $cc = new cam_control_archive($this->uid,$cam->cam_id,$pref);

        //todo: remove 9000
        $stream_port = $cam->id+9000;
        $input = $cc->gen_input_string($cam->live_proto, $cam->ip, $cam->live_port, $cam->live_path);
        $output = $cc->gen_live_string($stream_port, $cam->stream_path);
        $stream = $cc->get_stream_string($stream_port, $cam->stream_path);

        switch($pref){
            case 'live':
                $cc->create($input, $output);
                break;
            case 'rec':
                $path = DIR."/rec/$this->uid";
                $cc->create(new VLMInput($stream), $cc->gen_rec_string($path));
                break;
            case 'mtn':
                $path = DIR."/mtn/$this->uid";
                $cc->create(new VLMInput($stream), $cc->gen_rec_string($path));
                break;
        }

        if($debug){
            echo "$cam->cam_id $pref\n";
            //входные данные
            echo "Данные камеры\n";
            echo $input."\n";
            //выходные данные
            echo $output."\n";
            echo $stream."\n";
        }
    }

    /**
     * @param CamID $cid
     * @param CamPrefix $pref
     * @param YesNo $debug
     */
    public function play_cam(CamID $cid, CamPrefix $pref, YesNo $debug){
        $cam = $this->cams[$cid->get()];
        $cc = new cam_control_archive($this->uid,$cam->cam_id,$pref);

        switch($pref){
            case 'live':
                if($debug)  echo "$cam->cam_id live\n";
                if($cam->live) $cc->play();
                break;
            case 'rec':
                if($cam->live && $cam->rec){
                    if($debug) echo "$cam->cam_id rec\n";
                    $cc->play();
                }
                break;
            case 'mtn':
                if($cam->live && $cam->mtn){
                    if($debug) echo "$cam->cam_id mtn\n";
                    $cc->play();
                }
                break;
        }
    }

    /**
     * @param CamID $cid
     * @param CamPrefix $pref
     * @param YesNo $debug
     */
    public function delete_cam(CamID $cid, CamPrefix $pref, YesNo $debug){
        $cam = $this->cams[$cid->get()];
        $cc = new cam_control_archive($this->uid,$cam->cam_id,$pref);

        $cc->delete();
    }

    /**
     * @param CamID $cid
     * @param CamPrefix $pref
     * @param YesNo $debug
     */
    public function stop_cam(CamID $cid, CamPrefix $pref, YesNo $debug=0){
        $cam = $this->cams[$cid->get()];
        $cc = new cam_control_archive($this->uid,$cam->cam_id,$pref);

        $cc->stop();
    }

    /**
     * Запуск unix процесса
     */
    public function start() {
        $this->create_user_dirs();

        if($this->dyn){
            $vlc_vlm = '';
        }
        else{
            $vlc_vlm_path = ETC."/$this->uid/$this->uid.vlm";
            $vlc_vlm = "--vlm-conf $vlc_vlm_path";
        }

        $vlc_ifs = "-I http --http-host ".LIVEHOST." --http-port $this->ht_port -I telnet --telnet-port $this->tl_port  --telnet-password ".TLPWD;
        $vlc_logs = "--extraintf=http:logger --file-logging --log-verbose 0 --logfile $this->log";
        $vlc_shell = VLCBIN." --ffmpeg-hw --http-reconnect --http-continuous --sout-keep ".VLCD." $vlc_ifs  --repeat --loop --network-caching ".VLCNETCACHE." --sout-mux-caching ".VLCSOUTCACHE." $vlc_vlm --pidfile $this->proc $vlc_logs \n";

        //перезапись конфигов
        {
            if(!$this->dyn){
                //перезапишем наш конфиг
                $f = fopen($this->path_vlm, "w");
                fwrite($f, $this->vlm);
                fclose($f);
            }
            //запишем логротейт
            $f = fopen($this->logrotate,"w");
            fwrite($f, $this->config->logrotate());
            fclose($f);
        }


        //старт vlc
        if(is_file($this->proc)){
            echo $this->error(__LINE__, "VLC для пользователя $this->uid уже запущен или мертв");
        }
        else{
            echo "vlc: $vlc_shell\n";   //комманда запуска
            (new BashCommand($vlc_shell))->exec();

            //todo: сделать проверку на успешный старт unix процесса
            if($this->dyn){
                $this->wait_for_unix_proc_start();
                //динамически добавить наши камеры
                echo "Динамическое добавление\n";
                foreach($this->cams as $cam)
                {
                    $this->create_cam($cam->cam_id, new CamPrefix('live'), new YesNo(true));
                    $this->create_cam($cam->cam_id, new CamPrefix('rec'), new YesNo(true));
                    $this->create_cam($cam->cam_id, new CamPrefix('mtn'), new YesNo(true));

                    $this->play_cam($cam->cam_id, new CamPrefix('live'), new YesNo(true));
                    $this->play_cam($cam->cam_id,new CamPrefix('rec'), new YesNo(true));
                }
            }
        }
    }

    /**
     * ждем 1 секунду пока стартанет процесс vlc
     */
    protected function wait_for_unix_proc_start(){
        sleep(1);
    }

    /**
     * шутдаун процесса через telnet
     */
    public function stop() {
        $f = $this->telnet->connect('localhost', $this->tl_port);
        if(!$f){
            echo "Порт закрыт \n";
        }else
        {
            echo "Успешное подключение \n";
            $this->telnet->auth(TLPWD);
            $this->telnet->write('shutdown');
            echo $this->telnet->read();
            sleep(3);
        }
    }

    /**
     * собрать статус
     */
    public function status() {
        $ret = '';
        if(is_file($this->proc)){
            $ret.= "PROC файл на месте \n";
        }
        $ps = "ps -aef | grep vlc | grep /proc/$this->uid | grep -v grep ";
        //echo $ps;
        $status = shell_exec($ps);
        if($status=='') $ret.= "Оно мертво\n";
        else
            $ret.= $status;
        echo $ret;
    }

    /**
     * запущен ли vlc
     * @return int
     */
    public function is_run(){
        $ret = '';
        if(is_file($this->proc)){
            $ret.= "PROC файл на месте \n";
        }
        $ps = "ps -aef | grep vlc | grep /proc/$this->uid | grep -v grep ";
        //echo $ps;
        $status = shell_exec($ps);

        if($status=='') return 0;
        else return 1;
    }

    /**
     * перезапустить vlc процесс
     */
    public function restart() {
        $this->stop();
        $this->start();
    }

    /**
     * удалить proc файл
     */
    public function del_proc() {
        if(is_file($this->proc)) unlink($this->proc);
    }

    /**
     * убить процесс, если не указан процесс, то ищем проц файл по id пользователя
     * @param int $proc id unix процесса
     */
    public function kill($proc = 0) {
        if(!$proc){
            if(is_file($this->proc)){
                $kill = "kill `cat $this->proc`";
                (new BashCommand($kill))->exec();
                $this->del_proc();
            }
            else
            {
                echo "файл $this->proc не найден, если процесс запущен, то его нужно искать в процесс листе\n";
            }
        }
        else 
        {
            $kill = "kill $proc";
            (new BashCommand($kill))->exec();
            $this->del_proc();
        }
        sleep(1);
    }

    /**
     * найти через grep в процесс листах наш процесс по id пользователя
     * @return int id unix процесса
     */
    public function ps_get_proc() {
        $ps = "ps -aef | grep vlc | grep /proc/$this->uid | grep -v grep | awk ' {print $2} '";
        $proc = (int)shell_exec($ps);
        return $proc;
    }

    /**
     * убить процесс по id пользователя, ищет по процесс листу в системе
     */
    public function ps_kill() {
        $proc = $this->ps_get_proc();
        $this->kill($proc);
    }

    /**
     * Создать рабочии дирректории, если их нет
     */
    public function create_user_dirs() {
        foreach($this->dirs as $dir){
            $path = DIR."/$dir/".$this->get_uid();
            if(!is_dir($path))
                mkdir($path, 0775);
        }
    }

    /**
     * проверить папки на факт монтирования
     * @return bool
     */
    public function is_mounted(){
        // etc mtab
        //todo: организовать проверку
        return true;
    }
}

