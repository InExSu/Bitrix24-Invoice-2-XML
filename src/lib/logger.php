<?php

/**
 * 
 */
class Logger
{
	private $iLogSize = 100;
	private $arCache;
	private $sFileName;

	function __construct(string $sFileName)
	{
		$this->sFileName = $sFileName;
		$this->arCache = [];

		$handle = @fopen($sFileName, "c+");
		if ($handle) 
		{
    		while (($buffer = fgets($handle, 4096)) !== false) 
    		{
        		$this->arCache[] = $buffer;
    		}
    		if (!feof($handle)) 
    		{
        		echo "Error: unexpected fgets() fail\n";
    		}
    		fclose($handle);
		}
		
	}

	function write(string $sLine)
	{
		$sLogLineFormatted = '[' . date('Y-m-d H:i:s') . '] '  . $sLine . "\r\n";
		array_unshift($this->arCache, $sLogLineFormatted);
		while (count($this->arCache) > $this->iLogSize)
		{
			array_pop($this->arCache);
		}

		file_put_contents($this->sFileName, $this->arCache);
	}

	function clean()
	{
		file_put_contents($this->sFileName, '');
	}	
}