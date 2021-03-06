<?php
/**
 * Created by PhpStorm.
 * User: calc
 * Date: 16.06.14
 * Time: 10:56
 */

namespace system2;

/**
 * Class BBFactory
 * @package system2
 */
class BBFactory extends AbstractFactory {
    /**
     * @return ISystem
     */
    public function createSystem()
    {
        $system =  parent::createSystem();

        $e = new BBLogMotionEvent(Motion::EVENT_MOTION_START);
        $system->addEventHandler($e);

        $e = new BBLogMotionEvent(Motion::EVENT_MOTION_STOP);
        $system->addEventHandler($e);

        $e = new BBLogMotionEvent(Motion::EVENT_MOTION_DETECTED);
        $system->addEventHandler($e);

        $e = new BBLogMotionEvent(Motion::EVENT_CAMERA_LOSS);
        $system->addEventHandler($e);

        $recMotionEvent = new BBRecMotionEvent(Motion::EVENT_MOTION_START);
        $system->addEventHandler($recMotionEvent);
        $recMotionEvent = new BBRecMotionEvent(Motion::EVENT_MOTION_STOP);
        $system->addEventHandler($recMotionEvent);

        //удалить записи старше 30 дней при каждом update
        //$system->addPermanentCommand(new RotateRecCommand());
        $this->addPermanentCommand(new RotateRecCommand());

        $this->addCommands($system);

        return $system;
    }

    /**
     * @param DVR $dvr
     * @return array of Daemons
     */
    protected function createDaemons(DVR $dvr)
    {
        $vlc = new Vlc($dvr);
        $this->addPermanentCommand(new BBDaemonWatchdog($vlc));

        // нам необходимы только те камеры, в которых включен mtn
        $cams = $dvr->getCamIDs();

        $ids = array();
        foreach($cams as $id){
            $cam = $dvr->getCam($id);
            $cs = $cam->getSettings();
            /** @var $cs BBCamSettings  */
            if($cs->mtn) $ids[] = $id;
        }

        $motion = new Motion($dvr, $ids);
        if(count($cams))
            $this->addPermanentCommand(new BBDaemonWatchdog($motion));

        return array($vlc, $motion);
    }

    /**
     * @return array
     */
    public function createUsers()
    {
        $users = array();
        $db = \Database::getInstance();
        $q = "select id from users where banned=0";
        $r = $db->query($q);
        while(($row = $r->fetch_row())){
            try{
                $users[] = AbstractFactory::getInstance()->createUser($row[0]);
            }
            catch(\Exception $e){
                Log::getInstance()->put($e->getCode().' '.$e->getMessage()."\n".$e->getTraceAsString()."\n", __CLASS__, Log::ERROR);
            }
        }

        return $users;
    }

    /**
     * @param DVR $dvr
     * @return array
     */
    protected function createCams(DVR $dvr){
        $db = \Database::getInstance();
        $q = mysql::getQuery(mysql::CAM_SETTINGS, array('{dvr_id}' => $dvr->getID()));
        $r = $db->query($q);

        $cams = array();
        while(($row = $r->fetch_object('system2\BBCamSettings')) != null){
            /** @var BBCamSettings $row */

            //$dvr->addCam(new BBCam($this, $row));
            $cam = $this->createCam($dvr, $row);
            $cams[] = $cam;

            //Так как у нас Motion создает раз в минуту картинку, то создаем таймлапсы
            $timelapse = new BBArchiveTimelapseCommand($cam);
            $this->addPermanentCommand($timelapse);
        }
        return $cams;
    }

    /**
     * @param ICam $cam
     * @return ICamStream
     */
    public function createStream(ICam $cam)
    {
        $stream = new Streams($cam);

        $cs = $cam->getSettings();
        /** @var $cs BBCamSettings */
        $motion = new MotionStream($cam, $cs);
        $motion->setEnabled($cs->live && $cs->mtn);
        $stream->addStream($motion);

        $live = new BBLiveStream($cam);
        $live->setTestInputCommand(new BBTestInputFailSaveCommand($cam, $live));
        $live->setEnabled($cs->live);
        $stream->addStream($live);

        $hls = new HLSVlcStream($cam, $live);
        $hls->setEnabled($cs->live);
        $stream->addStream($hls);
        //$this->streams[] = new FlvVlcReStream($this, $live);

        //nginx rtmp stream
        //$this->streams[] = new RtmpVlcReStream($this, $live);

        $rec = new BBRecStream($cam, $live);
        $rec->setEnabled($cs->live && $cs->rec);
        $rec->setTestInputCommand(new BBTestInputFailSaveCommand($cam, $rec));
        $stream->addStream($rec);

        $mtn = new BBRecStream($cam, $live, TIME_LOCK_RECORD, Path::MOTION);
        $mtn->setEnabled($cs->live && $cs->mtn && BBRecMotionEvent::isMotion($cam));
        $mtn->setTestInputCommand(new BBTestInputFailSaveCommand($cam, $mtn));
        $stream->addStream($mtn, Path::MOTION);

        //motion flv stream
        $flv = new UrlFlvVlcStream($cam, "http://localhost:".(MOTION_STREAM_PORT + $cam->getID()));
        $flv->setEnabled($cs->live);
        $stream->addStream($flv);

        return $stream;
    }
} 