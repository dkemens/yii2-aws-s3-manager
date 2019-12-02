<?php

namespace dkemens\s3mediamanager\controllers;

use yii\web\Controller;
use yii\filters\{VerbFilter, AccessControl};
use dkemens\s3mediamanager\Module as dks3module;
use dkemens\s3mediamanager\components\{S3Constructor, S3Manager};

/**
 * Default controller for the `myjsiCommon` module
 */
class DefaultController extends Controller
{

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'rules' => [
                    [
                        'allow' => true,
                        'actions' => ['index', 'upload', 'download', 'delete', 'get-bucket-object', 'get-object', 'create-folder', 'delete-folder'],
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'upload' => ['post'],
                    'create-folder' => ['post'],
                    'delete-folder' => ['post'],
                ],
            ],
        ];
    }

    /**
     * Renders the index view for the module
     * @return string
     */
    public function actionIndex()
    {
        return $this->render('index', []);
    }

    public function actionGetBucketObject( $justFolders = false )
    {
        $s3 = $this->instantiateS3Constructor();
        $s3->buildBucket();

        if ( $justFolders === true )
            return ( json_encode($s3->folderObject) );
        
        return (json_encode(['bucketObject' => $s3->bucketObject, 'folderObject' => $s3->folderObject]));
    }

    public function actionGetObject ( $key = null, $justPath = true ) : string 
    {
        if ( $key !== null )

            if ( $justPath === true )
                return json_encode(['effectiveUrl' => 'https://s3.amazonaws.com/'.$this->getBucketName().'/'.$key]);

        $s3 = $this->instantiateS3Constructor();
        $objectHead = $s3->getObjectHead($this->getBucketName(), $key);
        $parts = explode("/", $key);
        $fileType = $s3->getType(end($parts));
        $objectRow = [ 
            'text' => end($parts),
            'id' => $key,
            'modified' => $s3->getModifiedDate($objectHead['LastModified']),
            'icon' => $fileType['icon'],
            'filetype' => $fileType['type'],
            'size' => \Yii::$app->formatter->asSize($objectHead['@metadata']['headers']['content-length']),
            'effectiveUrl' => 'https://s3.amazonaws.com/'.$this->getBucketName().'/'.$key,
        ];
        return json_encode($objectRow);            

        return json_encode(['error' => 'Could not load object head; no key provided.']);
    }

    /**
     * Retrieves a private s3 object and offers it up for download to the client
     * @return [type] [description]
     */
    public function actionDownload($key)
    {
        $s3 = new S3Manager([
            'bucket' => $this->getBucketName(),
        ]);

        $file = $s3->download($key);
        $filename = explode("/", $key);

        header("Content-Type: {$file['ContentType']}");
        header('Content-Disposition: attachment; filename='.end($filename));
        echo $file['Body'];
    }

    /**
     * Uploads a file to the s3 bucket
     */
    public function actionUpload()
    {
        set_time_limit(0);
        if (isset($_FILES['file']))
        {
            $uploaded = \yii\web\UploadedFile::getInstanceByName('file');
            $fileContents = file_get_contents($uploaded->tempName);

            $s3 = $this->uploadPkgFile (
                strlen(\Yii::$app->request->post('s3mm-upload-path')) >= 2 ? ltrim(\Yii::$app->request->post('s3mm-upload-path'), '/') : '/',
                $fileContents,
                $uploaded->name
            );

        }

        return json_encode($s3);
    }

    /**
     * Creates a folder in s3
     * @var  string post['name'] the name of the folder
     * @var  string post['parent'] the path in which to put the new folder
     * @return boolean whether or not the folder was created
     */
    public function actionCreateFolder()
    {
        if  ( \Yii::$app->request->post('name') && \Yii::$app->request->post('parent') )
        {
            $s3 = new S3Manager([
                'bucket' => $this->getBucketName(),
            ]);

            $path = \Yii::$app->request->post('parent').'/'.\Yii::$app->request->post('name');

            return $s3->createFolder($path);
        }

        throw new \yii\web\BadRequestHttpException('Unable to create folder. Check parameters or aws configuration.');
    }

    /**
     * Deletes a folder in s3
     * @var  string post['key'] the name of the folder
     * @return boolean whether or not the folder was created
     */
    public function actionDeleteFolder()
    {
        if  ( \Yii::$app->request->post('key') )
        {
            $s3 = $this->instantiateS3Constructor();

            $folderObjects = $s3->listObjects(ltrim(\Yii::$app->request->post('key'), '/'));

            /** We can't delete a folder if it's not empty,  */
            $objectsInFolder = 0;

            if ( is_object($folderObjects) && is_array($folderObjects['Contents']) )
            {
                foreach ( $folderObjects['Contents'] as $object )
                {
                    if ( !preg_match('/.folder/', $object['Key']) )
                    {
                        $objectsInFolder++;
                    }
                }
            }

            /** If it's not empty, return an error */
            if ( $objectsInFolder > 0 )
                return json_encode(['error' => 'folder not empty']);
            
            $manager = new S3Manager([
                'bucket' => $this->getBucketName(),
            ]);

            $manager->delete( trim(\Yii::$app->request->post('key'), '/').'/.folder');
            $manager->delete( ltrim(\Yii::$app->request->post('key'), '/') );

            return true;
        }

        throw new \yii\web\BadRequestHttpException('Unable to delete folder. Check parameters or aws configuration. Perhaps the folder is not empty?');
    }

    /**
     * Deletes an s3 object
     * @var  string $key the s3 object key
     * @return json_encoded s3 response
     */
    public function actionDelete($key)
    {
        $s3 = new S3Manager([
            'bucket' => $this->getBucketName(),
        ]);

        return json_encode($s3->delete($key)); // false or the url        
    }

    /**
     * Instantiates the s3 constructor object
     * @param      string $delimiter the delimiter parameter (used for listObjects etc)
     * @return the s3Constructor object
     */
    private function instantiateS3Constructor( $delimiter = null )
    {
        $parameters = [
            's3bucket' => $this->getBucketName(),
            's3region' => $this->getRegionName(),
            's3prefix' => $this->getPrefix(),
            ];
            
        if ( $delimiter !== null )
            $parameters['delimiter'] = $delimiter;

        return new S3Constructor($parameters);
    }

    private function uploadPkgFile ( string $path, string $body, string $filename )
    {
        // If there's a / in the name, s3 will treat it as a folder. Nix that.
        $filename = str_replace('/', '', $filename);

        $path = $path === '/' ? $filename : $path.$filename;

        $s3 = new S3Manager([
            'bucket' => $this->getBucketName(),
            'key' => $path,
            'body' => $body,
        ]);

        return $s3->upload(); // false or the url
    }

    /**
     * Gets the bucket name from session, params, or module configuration
     *
     * @throws     \yii\web\BadRequestHttpException  if there is no bucket configured, 
     *
     * @return     string                            The bucket name
     */
    private function getBucketName() : string
    {
        $session = \Yii::$app->session;

        /**
         * Check on the fly configuration first
         */
        if ( $session->has(dks3module::SESSION_BUCKET_KEY) && null !== $session->get(dks3module::SESSION_BUCKET_KEY) )
            return $session->get(dks3module::SESSION_BUCKET_KEY);

        /**
         * Next check parameters
         */
        if ( isset(\Yii::$app->params['s3bucket']) )
            return \Yii::$app->params['s3bucket'];

        /**
         * Finally, check module configuration
         */
        if ( array_key_exists('bucket', \Yii::$app->modules['s3mediamanager']['configuration']) )
            return \Yii::$app->modules['s3mediamanager']['configuration']['bucket'];

        throw new \yii\web\BadRequestHttpException('There is no bucket configuration. Please refer to the Readme');
    }


    /**
     * Gets the region name from session, params, or module configuration
     *
     * @throws     \yii\web\BadRequestHttpException  if there is no region configured, 
     *
     * @return     string                            The region name
     */
    private function getRegionName() : string
    {
        $session = \Yii::$app->session;

        /**
         * Check on the fly configuration first
         */
        if ( $session->has(dks3module::SESSION_REGION_KEY) && null !== $session->get(dks3module::SESSION_REGION_KEY) )
            return $session->get(dks3module::SESSION_REGION_KEY);

        /**
         * Next check parameters
         */
        if ( isset(\Yii::$app->params['s3region']) )
            return \Yii::$app->params['s3region'];

        /**
         * Finally, check module configuration
         */
        if ( array_key_exists('region', \Yii::$app->modules['s3mediamanager']['configuration']) )
            return \Yii::$app->modules['s3mediamanager']['configuration']['region'];

        throw new \yii\web\BadRequestHttpException('There is no region configuration. Please refer to the Readme');
    }

    /**
     * Gets the s3 prefix from session, params, or module configuration
     *
     * @throws     \yii\web\BadRequestHttpException  if there is no prefix configured, 
     *
     * @return     string                            The prefix name
     */
    private function getPrefix() : string
    {
        $session = \Yii::$app->session;

        /**
         * Check on the fly configuration first
         */
        if ( $session->has(dks3module::SESSION_PREFIX_KEY) && null !== $session->get(dks3module::SESSION_PREFIX_KEY) )
            return $session->get(dks3module::SESSION_PREFIX_KEY);

        /**
         * Next check parameters
         */
        if ( isset(\Yii::$app->params['s3prefix']) )
            return \Yii::$app->params['s3prefix'];

        /**
         * Finally, check module configuration
         */
        $manager = \Yii::$app->getModule('s3mediamanager');
        if ( array_key_exists('s3prefix', $manager->configuration) )
            return $manager->configuration['s3prefix'];

        return null;
    }

}