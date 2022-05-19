<?php

/**
 * 
 */
class FTPManager
{
	private $sHost;
	private $iPort;
	private $sLogin;
	private $sPassword;
	private $rFTP;

	function __construct(string $sHost = "", int $iPort = 21, string $sLogin = "", string $sPassword = "")
	{
		$this->sHost = $sHost;
		$this->iPort = $iPort;
		$this->sLogin = $sLogin;
		$this->sPassword = $sPassword;
	}

	public function connect()
	{
		$this->rFTP = ftp_connect( $this->sHost, $this->iPort, 90);
		if (!$this->rFTP)
			throw new Exception("Не удалось подключиться к ftp-серверу. Проверьте адрес и порт");
			
		$bConnectResult = ftp_login($this->rFTP, $this->sLogin, $this->sPassword);		

		if (!$bConnectResult)
			throw new Exception("Не удалось подключиться к ftp-серверу. Проверьте логин и пароль");

		ftp_pasv( $this->rFTP, true);
		
		return  $bConnectResult;
	}

	public function upload(string $sFileName, string $sLocalDirectory = "", string $sRemoteDirectory = "")
	{
		$sLocalPath = $sLocalDirectory . $sFileName;
		if (!file_exists($sLocalPath))
			throw new Exception("Не найден файл для загрузки на сервер:" . $sLocalPath);

		
		$sRemotePath = /*$sRemoteDirectory .*/ $sFileName;
		if ($sRemoteDirectory != "")
		{
			$arRemotePath = array_filter(explode("/", $sRemoteDirectory));
			foreach ($arRemotePath as $sDirectoryName)
			{
				$sDirectoryName = strval($sDirectoryName);
				if (is_string($sDirectoryName))
					ftp_chdir($this->rFTP, $sDirectoryName);
			}
		}
		
		$rUploadProcess = ftp_nb_put($this->rFTP, $sRemotePath, $sLocalPath, FTP_BINARY, FTP_AUTORESUME);

		while (FTP_MOREDATA == $rUploadProcess)
    	{
        	$rUploadProcess = ftp_nb_continue($this->rFTP);
    	}	
	}

	public function disconnect()
	{
		if (!$this->rFTP)
			throw new Exception("Нет активного ftp подключения");

		ftp_close($this->rFTP);
	}
}