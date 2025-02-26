<?php

/* Copyright (C) 2022 Manticore Software Ltd
* You may use, distribute and modify this code under the
* terms of the AGPLv3 license.
*
* You can find a copy of the AGPLv3 license here
* https://www.gnu.org/licenses/agpl-3.0.txt
*/

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

class DataGetter
{
  const STATUS_SUCCESS = "success";
  const STATUS_ERROR = "error";

  private function printResponse($data, $status = self::STATUS_SUCCESS, $code = 200)
  {
    $result = ['status' => $status];
    if ($status === self::STATUS_ERROR) {
      if ($code === 200) {
        $code = 500;
      }
      $result['message'] = $data;
    } else {
      $result['result'] = $data;
    }


    try {
      $encodedResult = json_encode($result);
    } catch (Exception $e) {
      $code = 500;
      $encodedResult = '{"status":"'.self::STATUS_ERROR.'", "message":"Error while json encoding"}';
    }

    http_response_code($code);
    echo $encodedResult;

    die();
  }

  public function query()
  {
    $curl = curl_init();

    $query = "select test_name, memory, original_query, engine_name, type, avg(fastest), avg(slowest), avg(avg_fastest), min(checksum), max(query_timeout), group_concat(error) from results where error is NULL and query_timeout = 0 group by test_name, engine_name, type, original_query, memory order by original_query asc limit 1000000";
    // the below enables the mode which allows to visualize different attempts of the same engine + type, see also $engine = below
    //$query = "select test_name, test_time m, memory, original_query, engine_name, type, avg(fastest), avg(slowest), avg(avg_fastest), min(checksum), max(query_timeout), group_concat(error) from results where error is NULL and query_timeout = 0 group by test_name, test_time, engine_name, type, original_query, memory order by original_query asc limit 1000000";

    curl_setopt($curl, CURLOPT_URL, "http://db:9308/sql");
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, 'query=' . urlencode($query));

    $result = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if (empty($result)) {
      $this->printResponse("Server responsed with empty data", self::STATUS_ERROR);

      return false;
    }

    if ($httpCode != 200) {
      $this->printResponse("Response failed with error code ".$httpCode, self::STATUS_ERROR);

      return false;
    }


    $result = $this->perpareResponse($result);
    if (!$result) {
      $this->printResponse("Error while preparing data", self::STATUS_ERROR);
    }

    $this->printResponse($result);

    return true;
  }

  private function perpareResponse($result)
  {
    $result = json_decode($result, true);
    if (!json_last_error()) {
      $data = [];

      $engines = [];
      $tests = [];
      $memory = [];

      foreach ($result['hits']['hits'] as $row) {
        $row = $row['_source'];
        $tests[$row['test_name']] = 0;
        $memory[$row['memory']] = 0;
        $engine = $row['engine_name'].($row['type']?'_'.$row['type']:'');
        //$engine = $row['engine_name'].'_'.$row['m'].($row['type']?'_'.$row['type']:'');
        $engines[$engine] = 0;

        // converting from microseconds to milliseconds
        foreach (['avg(fastest)', 'avg(slowest)', 'avg(avg_fastest)'] as $v) $row[$v] = round($row[$v]/1000);

        // TODO: the timeout/error branch below is never executed, because we filter this out in the query above, but we need to be able to execute it and show error/timeout details in the UI
        if ($row['max(query_timeout)'] > 0 or $row['group_concat(error)']) $row['avg(fastest)'] = $row['avg(slowest)'] = $row['avg(avg_fastest)'] = -1;
        else {
          // let's round times lower than 1ms to 1ms
          if ($row['avg(fastest)'] < 1) $row['avg(fastest)'] = 1;
          if ($row['avg(slowest)'] < 1) $row['avg(slowest)'] = 1;
          if ($row['avg(avg_fastest)'] < 1) $row['avg(avg_fastest)'] = 1;
        }

        $data[$row['test_name']][$row['memory']][md5($row['original_query'])]['query'] = $row['original_query'];
        $data[$row['test_name']][$row['memory']][md5($row['original_query'])][$engine] = [
          'slowest' => $row['avg(slowest)'],
          'fastest' => $row['avg(fastest)'],
          'fast_avg' => $row['avg(avg_fastest)'],
          'checksum' => $row['min(checksum)'],
        ];
      }

      krsort($engines, SORT_STRING);

      $sorted = [];
      foreach (['engines', 'tests', 'memory'] as $item) {
        $firstKey = $this->array_key_first($$item);
        $$item[$firstKey] = 1;

        foreach ($$item as $name => $row) {
          $sorted[$item][] = [$name => $row];
        }
      }


      $data = [
        'data' => $data,
        'tests' => $sorted['tests'],
        'engines' => $sorted['engines'],
        'memory' => $sorted['memory']
      ];

      return $data;
    }

    return false;
  }


  private function array_key_first(array $array)
  {
    return key(array_slice($array, 0, 1));
  }
}


$dg = new DataGetter();
$dg->query();
