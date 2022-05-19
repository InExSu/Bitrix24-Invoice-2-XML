<?

error_reporting(E_ALL);
ini_set("display_errors", 1);
spl_autoload_register(
	function ($class_name) 
	{
    	include 'src/lib/' . strtolower($class_name) . '.php';
	}
);

# настройки ftp подключения для выгрузки файла
define("FTP_HOST", "vh312.timeweb.ru");
define("FTP_PORT", "21");
// define("FTP_LOGIN", "cq86801_obmen");
define("FTP_LOGIN", "cq86801");
// define("FTP_PASSWORD", "5SkDfmNs");
define("FTP_PASSWORD", "YyZNN8Yi5uQm");
define("FTP_REMOTE_DIR", "/");

define("FTB_VERBOSE", true);


define("APP_TOKEN", "96w4seimao74eruyyhllkav2vxnyajb8");

define('C_REST_WEB_HOOK_URL','https://zelinskygroup.bitrix24.ru/rest/921/qvaz5n320ux33m4m/');

