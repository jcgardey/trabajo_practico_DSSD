<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use AppBundle\Entity\Work;
use AppBundle\Form\WorkType;

define ('CREDENTIALS_PATH', __DIR__.'/credentials/token.json');
define ('CLIENT_SECRET_PATH', __DIR__.'/credentials/client_secret.json');
define ('REDIRECT_URI', 'http://localhost:8000/save_token');
define ('SCOPE', 'https://www.googleapis.com/auth/drive');

class WorkController extends Controller
{

    private function getClient () {
        $client = new \Google_Client();
        $client->setAuthConfig(CLIENT_SECRET_PATH);
        $client->addScope(SCOPE);
        $client->setRedirectUri(REDIRECT_URI);
        $client->setAccessType('offline');
        return $client;
    }

    /*
        Carga el token desde el archivo indicado por la constante CREDENTIALS_PATH, en caso de que el token
        haya expirado, crea uno nuevo y lo actualiza en el archivo.
    */
    private function loadToken ($client) {
        $accessToken = json_decode(file_get_contents(CREDENTIALS_PATH),true);
        $client->setAccessToken($accessToken);
        if ($client->isAccessTokenExpired()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            file_put_contents(CREDENTIALS_PATH, json_encode($client->getAccessToken()));
        }
    }
    
    /**
     * @Route("/", name="registrar_trabajo")
     * @Method ({"GET", "POST"})
     */
    public function registrarTrabajoAction(Request $request) {
        $aWork = new Work ();   
        $form = $this->createForm(WorkType::class, $aWork);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

            $aWork->setState('PENDING');
            $em = $this->getDoctrine()->getManager();
            $em->persist($aWork);
            $em->flush();
            $request->getSession()->getFlashBag()->add('estado','El trabajo fue enviado correctamente');
        }
        return $this->render('work/new_work.html.twig',array('form' => $form->createView() ));  
    }

    private function createFile ($drive_service, $aWork) {
        $driveFile = new \Google_Service_Drive_DriveFile();
        $driveFile->setName($aWork->getTitle().'_'.$aWork->getAuthor().'.doc');
        $driveFile->setMimeType('application/vnd.google-apps.document');
        $createdFile = $drive_service->files->create($driveFile, array('mimeType' => 'application/vnd.google-apps.document'));

        $permission = new \Google_Service_Drive_Permission();
        $permission->setRole('writer');
        $permission->setType('user');
        $permission->setEmailAddress($aWork->getGmail());
        $drive_service->permissions->create($createdFile->getId(), $permission, array('sendNotificationEmail' => false));
        
        return $createdFile;
    }

    private function getFileLink($drive_service, $id_file) {
        $drive_file = $drive_service->files->get($id_file, array('fields' => 'webViewLink'));
        return $drive_file->getWebViewLink();
    }


    /**
     * @Route("/crear_archivo/{id}", name="crear_archivo")
     */
    public function crearArchivoDeTrabajoAction (Request $request, Work $aWork) {
        $client = $this->getClient();
                   
        $this->loadToken($client);

        $drive_service = new \Google_Service_Drive($client);
        $createdFile = $this->createFile($drive_service, $aWork);
           
        $link_to_file = $this->getFileLink($drive_service, $createdFile->getId());
        $link_to_end_edit = $this->generateUrl('end_edit',array('file_id' => $createdFile->getId() ),UrlGeneratorInterface::ABSOLUTE_URL);

        $response = new JsonResponse();
        $response->setData(array('file_link' => $link_to_file, 'link_to_end_edit' => $link_to_end_edit));
        return $response;
       
      
    }

    /**
     * @Route("/end_edit/{file_id}", name="end_edit")
     */
    public function endEditAction ($file_id) {
        $client = $this->getClient();
        $this->loadToken($client);
        $drive_service = new \Google_Service_Drive($client);
        $permissions = $drive_service->permissions->listPermissions($file_id,array('fields' => 'permissions'))->getPermissions();
        foreach ($permissions as $permission) {
            if ($permission->getRole() == 'writer') {
                $drive_service->permissions->delete($file_id,$permission->getId() );
            } 
        }
        $response = new JsonResponse();
        $response->setData('finalizo la edicion del archivo');
        return $response;
    }

    /**
     * @Route("/save_token", name="save_token")
     */
    public function saveTokenAction (Request $request) {
        $client = $this->getClient();
        if (!file_exists(CREDENTIALS_PATH) ) {
            if (!$request->get('code')) {
                $url = $client->createAuthUrl();
                return new RedirectResponse($url);
            }
            else {
                $code = $request->get('code');
                $accessToken =$client->fetchAccessTokenWithAuthCode($code);
                file_put_contents(CREDENTIALS_PATH, json_encode($accessToken)); 
            }
        }
        return new Response('Token Creado', Response::HTTP_OK, array('content-type' => 'text/html'));
    }
}
