<?php
/**
 * xavccClient provides an object oriented way to access xav.cc's api
 *
 * @package    xavcc
 * @author     Xavier Lacot <xavier@lacot.org>
 * @see        http://xav.cc/api
 */
class xavccClient
{
  protected
    $adapter          = null,
    $redirection_tool = 'http://xav.cc/',
    $server           = 'http://api.xav.cc/',
    $services         = array(
      'json'   => 'sf_short_url.json',
      'simple' => 'simple',
      'xml'    => 'sf_short_url.xml'
    );

  public function __construct($format = 'json')
  {
    if (!in_array($format, array_keys($this->services)))
    {
      throw new Exception('The format of the call can only be json, simple or xml.');
    }

    $adapter_class = sprintf('xavccClient%sAdapter', ucfirst($format));
    $this->adapter = new $adapter_class($this->server);
  }

  public function decode($alias)
  {
    if (0 === strpos($alias, $this->redirection_tool))
    {
      $alias = substr($alias, strlen($this->redirection_tool));
    }

    return $this->adapter->decode($alias);
  }

  /**
   * Encodes a long url, and returns the generated short url.
   *
   * @params $long_url   a long url
   *
   * @return string      the generated short url
   */
  public function encode($long_url, $alias = null)
  {
    return $this->adapter->encode($long_url, $alias);
  }
}

class xavccClientAdapter
{
  protected
    $server = null,
    $user_agent = 'xavccClient/0.1';

  public function __construct($server = 'http://api.xav.cc')
  {
    $this->server = $server;
    $this->curl = curl_init();
    curl_setopt($this->curl, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($this->curl, CURLOPT_USERAGENT, $this->user_agent);
    curl_setopt($this->curl, CURLOPT_HEADER, false);
    curl_setopt($this->curl, CURLOPT_AUTOREFERER, true);
    curl_setopt($this->curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($this->curl, CURLOPT_FRESH_CONNECT, true);
  }

  protected function call()
  {
    $response = curl_exec($this->curl);
    $requestInfo = curl_getinfo($this->curl);

    if ($requestInfo['http_code'] !== 200)
    {
      $response = false;
    }

    return $response;
  }

  protected function get($uri)
  {
    curl_setopt($this->curl, CURLOPT_URL, $uri);
    curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'GET');
    return $this->call();
  }

  protected function post($uri, $parameters)
  {
    curl_setopt($this->curl, CURLOPT_URL, $uri);
    curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'POST');

    if (!empty($parameters))
    {
      if (!is_array($parameters))
      {
        curl_setopt($this->curl, CURLOPT_POSTFIELDS, $parameters);
      }
      else
      {
        // multipart posts (file upload support)
        $has_files = false;

        foreach ($parameters as $name => $value)
        {
          if (is_array($value)) {
            continue;
          }

          if (is_file($value))
          {
            $has_files = true;
            $parameters[$name] = '@'.realpath($value);
          }
        }
        if($has_files)
        {
          curl_setopt($this->curl, CURLOPT_POSTFIELDS, $parameters);
        }
        else
        {
          curl_setopt($this->curl, CURLOPT_POSTFIELDS, http_build_query($parameters, '', '&'));
        }
      }
    }

    return $this->call();
  }
}

abstract class xavccClientRestAdapter extends xavccClientAdapter
{
  public function decode($alias)
  {
    $short_url = $this->decodeResponse(
      $this->get(
        sprintf('%s/%s?shorturl=%s', $this->server, $this->service_url, $alias)
      )
    );
    return isset($short_url[0]['longurl']) ? $short_url[0]['longurl'] : false;
  }

  public function encode($long_url, $alias)
  {
    $request = array('longurl' => $long_url);

    if ($alias)
    {
      $request['shorturl'] = $alias;
    }

    $request = $this->encodeRequest($request);
    $short_url = $this->decodeResponse(
      $this->post(
        sprintf('%s/%s', $this->server, $this->service_url),
        array('content' => $request)
      )
    );

    return (isset($short_url[0]['shorturl']) && $short_url[0]['shorturl']) ? $short_url[0]['shorturl'] : false;
  }
}

class xavccClientJsonAdapter extends xavccClientRestAdapter
{
  protected $service_url = 'sf_short_url.json';

  protected function decodeResponse($response)
  {
    return json_decode($response, true);
  }

  protected function encodeRequest($request)
  {
    return json_encode($request);
  }
}

class xavccClientSimpleAdapter extends xavccClientAdapter
{
  protected $service_url = 'simple';

  public function decode($alias)
  {
    $url = sprintf('%s/%s/decode?url=%s', $this->server, $this->service_url, $alias);
    return $this->get($url);
  }

  public function encode($long_url, $alias)
  {
    $url = sprintf('%s/%s/encode?url=%s', $this->server, $this->service_url, $long_url);

    if ($alias)
    {
      $url .= '&alias='.$alias;
    }

    return $this->get($url);
  }
}

class xavccClientXmlAdapter extends xavccClientRestAdapter
{
  protected $service_url = 'sf_short_url.xml';

  protected function decodeResponse($response)
  {
    $return = array();
    $result = @simplexml_load_string($response);

    if ($result)
    {
      foreach ($result as $value)
      {
        $return[] = array(
          'shorturl'        => (string)$value->Shorturl,
          'longurl'         => (string)$value->Longurl,
          'viewcount'       => (string)$value->Viewcount,
          'last_visited_at' => (string)$value->LastVisitedAt,
        );
      }
    }

    return $return;
  }

  protected function encodeRequest($request)
  {
    $xml = '<SfShortUrl>';

    foreach ($request as $key => $value)
    {
      $key = ucfirst($key);
      $trimed_value = ($value !== false) ? trim($value) : '0';

      if ($trimed_value !== '')
      {
        if (htmlspecialchars($trimed_value) != $trimed_value)
        {
          $xml .= '<'.$key.'><![CDATA['.$trimed_value.']]></'.$key.'>';
        }
        else
        {
          $xml .= '<'.$key.'>'.$trimed_value.'</'.$key.'>';
        }
      }
    }

    $xml .= '</SfShortUrl>';
    return $xml;
  }
}