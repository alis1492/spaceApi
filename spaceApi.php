<?php
/*
 * Plugin Name: spaceApi
 * Author: Hlieb Pohrebniak
 * Description: Wlasny API dla strony wordpress
 */

// error types
class APODError extends WP_Error {
    public function __construct() {
        parent::__construct(
            'apod_error', 
            'Nie udało się pobrać APOD', 
            array('status' => 500)
        );
    }
}
class MarsWeatherError extends WP_Error {
    public function __construct() {
        parent::__construct(
            'mars_weather_error', 
            'Nie udało się pobrać pogody na marsie', 
            array('status' => 500)
        );
    }
}

// DTO
// nie wszystko musi byc prywatnym
// refuse OOP, embrace tradition
// nie wprowadzam ubezpieczen bo ten route dziala na stalej API
// na wszelki przypadek zostaje wyswietlenie informacji z pliku JSON
class MarsWeatherDTO {
    // temperature params
    public float $temperatureAverage;
    public float $temperatureMax;
    public float $temperatureMin;

    // wind params
    public float $windAverage;
    // i dont really need min max wind speed
    // public string $windMax;
    // public string $windMin;
    public string $windDirection;

    // pressure params
    public float $pressureAverage;
    // it'll probably be comparison to earth's sea lvl pressure
    public float $pressureCompareToEarth;

    public string $season;

    public function __construct(object $sol)
    {
        // assume theres no mismatch in data, thus no validation
        $this->temperatureAverage = $sol->AT->av;
        $this->temperatureMax = $sol->AT->mx;
        $this->temperatureMin = $sol->AT->mn;

        $this->windAverage = $sol->HWS->av;
        $this->windDirection = $sol->WD->most_common->compass_point;

        $this->pressureAverage = $sol->PRE->av;
        // earthPre = marsPre
        //     /         /
        //    100        x
        $this->pressureCompareToEarth = round(100 * $this->pressureAverage / 101.325);

        $this->season = $sol->Season;
    }
}

// apod implementation
function prefixRegisterAPOD() {
    // YYMMDD    
    // construct the url for the current date
    // $urlStart = "https://apod.pl/apod/ap";
    // $date = date('ymd');
    // this should work for picture of today
    $url = 'https://apod.pl/apod/astropix.html';
    
    // fetch and validate the data
    $response = wp_remote_get($url);
    if(is_wp_error( $response )) {
        return new APODError();
    }

    // load DOM from html
    $html = wp_remote_retrieve_body( $response );
    $dom = new DOMDocument('1.0', 'utf-8');
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);

    // query class ! STATELESS !
    $xpath = new DOMXPath($dom);


    // nodes with their values extracted
    $descriptionNode = $xpath->query('//body/p')->item(1);
    $description = $dom->saveHTML($descriptionNode);
    $description = str_replace(["\n", "\r"], ' ', $description);

    $imageNode = $xpath->query('//img')->item(0);
    $imageUrl = $imageNode->attributes->getNamedItem('src')->nodeValue;
    if(!$imageUrl) {
        return new APODError();
    }

    // append hostname to the url
    $imageUrl = "https://apod.pl/apod/" . $imageUrl;

    $titleNode = $xpath->query("//body/center[2]/b[1]")[0];
    $title = $titleNode->nodeValue;

    return rest_ensure_response( [
        'title' => $title,
        'description' => $description,
        'imgUrl' => $imageUrl
    ] );
}

function registerAPOD() {
    register_rest_route('spaceApi/v1', '/apod', [
        'methods' => 'GET',
        'callback' => 'prefixRegisterAPOD',
    ]);
}

function prefixRegisterMarsWeather($req) {
    $apiKey = NASA_API_KEY;
    $url = "ttps://api.nasa.gov/insight_weather/?api_key=$apiKey&feedtype=json&ver=1.0";
    //      ^ typo here for development

    $body = "";
    // get json data
    $response = wp_remote_get( $url, [] );
    if(is_wp_error($response)) {
        // fallback to locally written json
        $body = file_get_contents(__DIR__ . "/weatherData.json");
        if($response != true) 
            return rest_ensure_response(new MarsWeatherError());
    } else {
        $body = wp_remote_retrieve_body( $response );
    }
    // json_decode returns TYPE not array
    // thus must use -> syntax 
    $json = json_decode($body);

    // filter sol key based on todays date
    $dayToSol = [
        "Mon" => '675',
        "Tue" => '676',
        "Wed" => '677',
        "Thu" => '678',
        "Fri" => '679',
        "Sat" => '690',
        "Sun" => '681'
    ];

    // get the sol key
    // either from query or todays date by default
    if(isset($req['sol'])) {
        $solKey = $dayToSol[$req['sol']];
    } else {
        $solKey = $dayToSol[date("D")];
    }

    $sol = $json->$solKey;
    $dto = new MarsWeatherDTO($sol);
    
    return rest_ensure_response( $dto );
}

// GET /spaceApi/v1/marsWeather?sol=Mon/Tue.../
function registerMarsWeather() {
    register_rest_route('spaceApi/v1', '/marsWeather', [
        'methods' => 'GET',
        'callback' => 'prefixRegisterMarsWeather'
    ]);
}

add_action( 'rest_api_init', 'registerAPOD' );
add_action('rest_api_init', 'registerMarsWeather');
?>