<?php
/****************************************************
 *                     phpConsole                   *
 *                                                  *
 * An object-oriented multi process manager for PHP *
 *                                                  *
 *                                                  *
 ****************************************************/

namespace Console;

use Console\Master;
use Console\Daemon;
use Console\Worker;
use Console\ProcessException;
use Closure;
use Exception;

/**
 * process manager
 */
class Manager
{

	public $appName = 'phpconsole';

	public $tmpDir = 'storage/tmp';

	/**
	 * operation system
	 *
	 * Linux/Darwin
	 *
	 * @var string
	 */
	private $os = '';

	/**
	 * master process object
	 *
	 * @var object Process
	 */
	private $master = '';

	/**
	 * daemon process object
	 *
	 * @var object Process
	 */
	private $daemon = '';

	/**
	 * worker process objects
	 *
	 * @var array [Process]
	 */
	public $workers = [];

	/**
	 * the pool for the worker that will be handle by the signal
	 *
	 * signal: string reload/stop
	 * pool: array [Process]
	 *
	 * @var array
	 */
	private $waitSignalProcessPool = [
		'signal' => '',
		'pool'   => [],
	];

	/**
	 * the closure object which will be inject in the worker object
	 *
	 * @var object Closure
	 */
	private $workBusinessClosure = '';

	/**
	 * minimum idle worker process number
	 *
	 * @var int
	 */
	// private $minNum = 1;

	/**
	 * maximum idle worker process number
	 *
	 * @var object Process
	 */
	// private $maxNum = 10;
	private $startNum = 5;

	/**
	 * linux user passwd
	 *
	 * @var string
	 */
	private $userPasswd = '';

	/**
	 * the directory name of the process's pipe will be storaged
	 *
	 * @var string
	 */
	private $pipeDir = '';

	/**
	 * support linux signals
	 *
	 * @var array
	 */
	private $signalSupport = [
		'reload'    => 10, // reload signal
		'stop'      => 12, // quit signal gracefully stop
		'terminate' => 15, // terminate signal forcefully stop
		'int'       => 2 // interrupt signal
	];

	/**
	 * hangup sleep time unit:microsecond /μs
	 *
	 * default 200000μs
	 *
	 * @var int
	 */
	private static $hangupLoopMicrotime = 200000;

	/**
	 * construct function
	 */
	public function __construct($config = [], Closure $closure)
	{
		// set timezone
		date_default_timezone_set( config('app.timezone', 'Asia/Shanghai') );

		// welcome
		$this->welcome();

		// configure
		$this->configure( $config );

		// 守护环境
//		$this->daemonEnv();

		// init master instance
		$this->master = new Master([
			'pipe_dir' => $this->pipeDir,
			'tmp_dir'  => $this->tmpDir,
			'app_name' => $this->appName,
		]);

		// init daemon instance
		$this->daemon = new Daemon([
			'pipe_dir' => $this->pipeDir,
			'tmp_dir'  => $this->tmpDir,
			'app_name' => $this->appName,
		]);

		// register worker business logic
		$this->workBusinessClosure = $closure;

		// int signal num
		$this->signalSupport = [
			'reload'    => SIGUSR1,
			'stop'      => SIGUSR2,
			'terminate' => SIGTERM,
			'int'       => SIGINT,
		];

		// exectue fork
		$this->execFork();

		// register signal handler
		$this->registerSigHandler();

		// hangup master
		$this->hangup();
	}

	/**
	 * daemon mode
	 *
	 * @return void
	 */
	public function daemonEnv()
	{
		$pid = pcntl_fork();

		if ( $pid == -1 ) {
			die( "fork(1) failed!\n" );
		} elseif ( $pid > 0 ) {
			//让由用户启动的进程退出
			exit( 0 );
		}

		// 脱离当前终端
		posix_setsid();
	}

	/**
	 * weclome slogan
	 *
	 * @return void
	 */
	public function welcome()
	{
		$welcome = <<<WELCOME
\033[36m
www.56br.com
			
An object-oriented multi process manager for PHP

Version: 0.5.0

\033[0m
WELCOME;

		echo $welcome;
	}

	/**
	 * the _get magic function
	 *
	 * @param string $name property name
	 */
	public function __get($name = '')
	{
		return $this->$name;
	}

	/**
	 * configure
	 *
	 * @param array $config
	 *
	 * @return void
	 */
	public function configure($config = [])
	{
		// set os type
		$this->os = $config[ 'os' ] ?? $this->os;

		// set user password
		$this->userPasswd = $config[ 'passwd' ] ?? '';

		// set worker start number
		$this->startNum = (int)$config[ 'worker_num' ] ?? $this->startNum;

		// set hangup sleep time
		self::$hangupLoopMicrotime = $config[ 'hangup_loop_microtime' ] ?? self::$hangupLoopMicrotime;

		// set pipe dir
		$this->pipeDir = $config[ 'pipe_dir' ] ?? config('storage.pipe', '') . DIRECTORY_SEPARATOR;

		$this->tmpDir = $config[ 'tmp_dir' ] ?? config('storage.temp', '') . DIRECTORY_SEPARATOR;

		$this->appName = $config[ 'app_name' ] ?? config('app.name', $this->appName);
	}

	/**
	 * define signal handler
	 *
	 * @param integer $signal
	 *
	 * @return void
	 */
	public function defineSigHandler($signal = 0)
	{
		switch ( $signal ) {
			// reload signal
			case $this->signalSupport[ 'reload' ]:
				// throw worker process to waitSignalProcessPool
				$this->waitSignalProcessPool = [
					'signal' => 'reload',
					'pool'   => $this->workers,
				];
				// push reload signal to the worker processes from the master process
				foreach ( $this->workers as $v ) {
					$v->pipeWrite( 'reload' );
				}
				break;

			// kill signal
			case $this->signalSupport[ 'stop' ]:
				// throw worker process to waitSignalProcessPool
				$this->waitSignalProcessPool = [
					'signal' => 'stop',
					'pool'   => $this->workers,
				];
				// push reload signal to the worker processes from the master process
				foreach ( $this->workers as $v ) {
					$v->pipeWrite( 'stop' );
				}
				break;

			case $this->signalSupport[ 'int' ]:
				foreach ( $this->workers as $v ) {
					// clear pipe
					$v->clearPipe();
					// kill -9 all worker process
					$result = posix_kill( $v->pid, SIGKILL );
					ProcessException::info( [
						'msg' => [
							'from'   => $this->master->type,
							'extra'  => "kill -SIGKILL {$v->pid}",
							'result' => $result,
						],
					] );
				}
				// clear pipe
				$this->master->clearPipe();
				$this->master->clearPid();
				// kill -9 master process
				echo "stop... \n";
				exit;
				break;

			case $this->signalSupport[ 'terminate' ]:
				foreach ( $this->workers as $v ) {
					// clear pipe
					$v->clearPipe();
					// kill -9 all worker process
					posix_kill( $v->pid, SIGKILL );
				}
				// clear pipe
				$this->master->clearPipe();
				$this->master->clearPid();
				// kill -9 master process
				echo "stop... \n";
				exit;
				break;

			default:

				break;
		}
	}

	/**
	 * register signal handler
	 *
	 * @return void
	 */
	private function registerSigHandler()
	{
		foreach ( $this->signalSupport as $v ) {
			pcntl_signal( $v, [ 'Console\Manager', 'defineSigHandler' ] );
		}
	}

	/**
	 * fork a worker process
	 *
	 * @return void
	 */
	private function fork()
	{
		$pid = pcntl_fork();

		switch ( $pid ) {
			case -1:
				// exception
				exit;
				break;

			case 0:
				try {
					// init worker instance
					$worker = new Worker( [
						'type'     => 'worker',
						'pipe_dir' => $this->pipeDir,
						'tmp_dir'  => $this->tmpDir,
						'app_name' => $this->appName,
					] );
					$worker->setProcessName();
					$worker->pipeMake();
					$worker->hangup( $this->workBusinessClosure );
				} catch ( Exception $e ) {
					ProcessException::error( [
						'msg' => [
							'msg'  => $e->getMessage(),
							'file' => $e->getFile(),
							'line' => $e->getLine(),
						],
					] );
				}

				// worker exit
				exit;
				break;

			default:
				try {
					$worker                = new Worker( [
						'type'     => 'worker',
						'pid'      => $pid,
						'pipe_dir' => $this->pipeDir,
						'tmp_dir'  => $this->tmpDir,
						'app_name' => $this->appName,
					] );
					$this->workers[ $pid ] = $worker;
				} catch ( Exception $e ) {
					ProcessException::error( [
						'msg' => [
							'msg'  => $e->getMessage(),
							'file' => $e->getFile(),
							'line' => $e->getLine(),
						],
					] );
				}
				break;
		}
	}

	/**
	 * execute fork worker operation
	 *
	 * @param int $num the number that the worker will be start
	 *
	 * @return void
	 */
	public function execFork($num = 0)
	{
		foreach ( range( 1, $num ?: $this->startNum ) as $v ) {
			$this->fork();
		}
	}

	/**
	 * hangup the master process
	 */
	private function hangup()
	{
		while ( true ) {
			// dispatch signal for the handlers
			pcntl_signal_dispatch();

			// daemon process
			$this->daemon->check( $this );

			// prevent the child process become a zombie process
			// pcntl_wait($status);
			foreach ( $this->workers as $k => $v ) {
				$res = pcntl_waitpid( $v->pid, $status, WNOHANG );
				// if ($res == -1 || $res = 0) {
				// 	// exception
				// 	continue;
				// }
				if ( $res > 0 ) {
					unset( $this->workers[ $res ] );

					if ( $this->waitSignalProcessPool[ 'signal' ] === 'reload' ) {
						if ( array_key_exists( $res, $this->waitSignalProcessPool[ 'pool' ] ) ) {
							unset( $this->waitSignalProcessPool[ 'pool' ][ $res ] );
							// fork a new worker
							$this->fork();
						}
					}

					if ( $this->waitSignalProcessPool[ 'signal' ] === 'stop' ) {
						if ( array_key_exists( $res, $this->waitSignalProcessPool[ 'pool' ] ) ) {
							unset( $this->waitSignalProcessPool[ 'pool' ][ $res ] );
						}
					}

				}
			}

			if ( $this->waitSignalProcessPool[ 'signal' ] === 'stop' ) {
				// all worker stop then stop the master process
				if ( empty( $this->waitSignalProcessPool[ 'pool' ] ) ) {
					$this->master->stop();
				}
			}

			// read signal from worker
			// $this->master->pipeRead();

			// precent cpu usage rate reach 100%
			usleep( self::$hangupLoopMicrotime );
		}
	}
}
