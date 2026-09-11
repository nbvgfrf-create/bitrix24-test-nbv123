<?php


namespace BX;


class BXConnector
{
    private $hook='';
    private $type='';
    public function __construct($hook,$type='hook')
    {
        $this->type=$type;
        $this->hook=$hook;

    }
    public function getListByField($entity,$id,$code='UF_CRM_1569088298',$select=['*','UF_*'])
    {
        $entities=$this->request('crm.'.$entity.'.list',[
            'filter'=>[
                $code=>$id
            ],
            'select'=>$select
        ]);

        if (empty($entities))
        {
            return false;
        }

        $filtered=[];
        foreach ($entities as $deal) {
            if (!is_array($id))
            {
                if ((string)$id===(string)$deal[$code])
                {
                    $filtered[]=$deal;
                }
                continue;
            }
            foreach ($id as $item) {
                if ((string)$item===(string)$deal[$code])
                {
                    $filtered[]=$deal;
                }
            }
        }
        return $filtered;
    }
    public function getIdByValue($type,$name,$value)
    {
        $options=$this->request('crm.'.$type.'.userfield.list',[
            'filter'=>[
                'FIELD_NAME'=>$name
            ]
        ]);
        foreach ($options[0]['LIST'] as $option) {

            if (strtolower($option['VALUE'])===strtolower($value))
            {
                return $option['ID'];
            }
        }
        return false;
    }
    public function getListBatch($method,$params=['select' => array('ID')],$objectsLeft=0, $id=0, $start=0, $batch=[],$list=[]){
        if ($objectsLeft==0){
            $resultList=$this->request($method,$params,'full');
            $objectsLeft=$resultList['total'];
            $params['start']=$start;
        }
        if ($objectsLeft<=50){
            $batch[$method.$id]=$method.'?'.http_build_query($params);

            $list=array_merge($list,current($this->request('batch',[
                'halt' => '0',
                'cmd' => $batch
            ])));
            $result=[];
            foreach($list as $element){
                $result=array_merge($result, $element);
            }
            return $result;
        }
        $batch[$method.$id]=$method.'?'.http_build_query($params);
        $id++;
        $start=$start+50;
        $objectsLeft=$objectsLeft-50;
        $params['start']=$start;

        if (count($batch)==50){
            $list=array_merge($list,current($this->request('batch',[
                'halt' => '0',
                'cmd' => $batch
            ])));
            $batch=array();
        }
        return $this->getListBatch($method,$params, $objectsLeft, $id, $start, $batch, $list);
    }

    public function getValueById($entity,$fieldCode,$id)
    {
        $options=$this->request('crm.'.$entity.'.userfield.list',[
            'filter'=>[
                'FIELD_NAME'=>$fieldCode
            ]
        ]);
        foreach ($options[0]['LIST'] as $option) {
            if ($option['ID']===$id)
            {
                return $option['VALUE'];
            }
        }
        return false;
    }

    public function getListItems($listId,$filter=[])
    {
        $answers=$this->request('lists.element.get',[
            'IBLOCK_TYPE_ID'=> 'lists',
            'IBLOCK_ID'=> $listId,
            'FILTER'=> $filter
        ]);

        $properties=$this->request('lists.field.get',[
            'IBLOCK_TYPE_ID'=> 'lists',
            'IBLOCK_ID'=> $listId,
        ]);
        $items=[];
        foreach ($answers as $i=>$item) {
            foreach ($properties as $property) {
                if (!array_key_exists('CODE',$property))
                {
                    continue;
                }
                if ($property['MULTIPLE']==='N')
                {
                    $items[$i][$property['CODE']]='';
                    continue;
                }
                $items[$i][$property['CODE']]=[];
            }
            foreach ($item as $key=>$value) {
                if (strpos($key,'PROPERTY_')===false)
                {
                    $items[$i][$key]=$value;
                    continue;
                }
                if ($properties[$key]['MULTIPLE']==='N')
                {
                    $items[$i][$properties[$key]['CODE']]=end($value);
                    continue;
                }
                $items[$i][$properties[$key]['CODE']]=$value;
            }
        }
        return $items;
    }
    public function request($method='profile',$params=[],$type='result'){
        if ($this->type!=='hook')
        {
            $params['auth']=$this->type;
        }

        $queryUrl = $this->hook.$method;

        $queryData = http_build_query($params);

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_POST => 1,
            CURLOPT_HEADER => 0,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $queryUrl,
            CURLOPT_POSTFIELDS => $queryData,
        ));
        $json = curl_exec($curl);
        curl_close($curl);
        if ($type==='json')
        {
            return $json;
        }

        $answer=json_decode($json,1);
        if ($type==='result'&&isset($answer['result']))
        {
            return $answer['result'];
        }
        if ($type==='full')
        {
            return $answer;
        }
        return $json;
    }
    public function getCompanyAddress($id)
    {
        if (!$id)
        {
            return '';
        }
        $adresses=$this->request('crm.address.list',[
            'filter'=>[
                'ENTITY_TYPE_ID'=>'4',
                'ENTITY_ID'=>$id
            ]
        ]);
        foreach ($adresses as $adress) {
            if($adress['ENTITY_TYPE_ID']==='8')
            {
                return [
                    "City"=> $adress["CITY"],
                    "PostalCode"=> $adress["POSTAL_CODE"],
                    "Line1"=> $adress["ADDRESS_1"],
                    "Country"=> $adress["COUNTRY"],
                    'CountrySubDivisionCode'=>$adress["PROVINCE"]
                ];
            }
        }
    }
    public function getList($method,$params,$start=0,$list=[]){
        $params['start']=$start;
        $resultList=$this->request($method,$params,'full');

        foreach($resultList['result'] as $element){
            $list[]=$element;
        }
        if (isset($resultList['next'])){
            return $this->getList($method,$params,$resultList['next'],$list);
        } else {
            return $list;
        }
    }
    public function getListBatchSPA($method,$params,$objectsLeft=0, $id=0, $start=0, $batch=[],$list=[]){

        if ($objectsLeft==0){
            $resultList=$this->request($method,$params,'full');
            $objectsLeft=$resultList['total'];
            $params['start']=$start;
        }

        if ($objectsLeft<=50){
            $batch[$method.$id]=$method.'?'.http_build_query($params);
            $list=array_merge($list,current($this->request('batch',[
                'halt' => '0',
                'cmd' => $batch
            ])));

            $result=[];
            foreach ($list as $itemLists) {
                foreach ($itemLists as $itemList) {
                    foreach ($itemList as $item) {
                        $result[]=$item;
                    }
                }
            }

            return $result;
        }
        $batch[$method.$id]=$method.'?'.http_build_query($params);
        $id++;
        $start=$start+50;
        $objectsLeft=$objectsLeft-50;
        $params['start']=$start;

        if (count($batch)==50){
            $list=array_merge($list,current($this->request('batch',[
                'halt' => '0',
                'cmd' => $batch
            ])));
            $batch=array();
        }


        return $this->getListBatchSPA($method,$params, $objectsLeft, $id, $start, $batch, $list);
    }
}