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

use \Doctrine\Common\Collections\ArrayCollection;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

use AppBundle\Entity\Work;
use AppBundle\Entity\Exposition;
use AppBundle\Form\WorkType;

define ('CREDENTIALS_PATH', __DIR__.'/credentials/token.json');
define ('REFRESH_TOKEN_PATH', __DIR__.'/credentials/refresh_token');
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

    /**
     *   Carga el token desde el archivo indicado por la constante CREDENTIALS_PATH, en caso de que el token
     *  haya expirado, crea uno nuevo y lo actualiza en el archivo.
     */
    private function loadToken ($client) {
        $accessToken = json_decode(file_get_contents(CREDENTIALS_PATH),true);
        $client->setAccessToken($accessToken);
        if ($client->isAccessTokenExpired()) {
            $refresh_token = file_get_contents(REFRESH_TOKEN_PATH);
            $client->fetchAccessTokenWithRefreshToken($refresh_token);
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
            $aWork->setDocumentFinished(false);

            $worksNumber = $this->getDoctrine()->getRepository('AppBundle:Work')->numberOfWorks();

            $aWork->setNumber($worksNumber + 1);
            
            $em = $this->getDoctrine()->getManager();

            $em->persist($aWork);
            $em->flush();
            $request->getSession()->getFlashBag()->add('estado','El trabajo fue enviado correctamente');

            return $this->render('work/work_sended.html.twig',array('work' => $aWork));
        }
        return $this->render('work/new_work.html.twig',array('form' => $form->createView() ));  
    }

    /**
     * Crea un archivo nuevo en Google Drive y lo comparte con la dirección de Gmail asociada al trabajo.
     */
    private function createFile ($drive_service, $aWork) {
        $driveFile = new \Google_Service_Drive_DriveFile();
        $driveFile->setName($aWork->getTitle().'_'.$aWork->getNumber().'.doc');
        $driveFile->setMimeType('application/vnd.google-apps.document');
        $createdFile = $drive_service->files->create($driveFile, array('mimeType' => 'application/vnd.google-apps.document'));

        $permission = new \Google_Service_Drive_Permission();
        $permission->setRole('writer');
        $permission->setType('user');
        $permission->setEmailAddress($aWork->getGmail());
        $drive_service->permissions->create($createdFile->getId(), $permission, array('sendNotificationEmail' => false));
        
        return $createdFile;
    }

    /**
     * A partir de un Id de archivo, retorna la URL del mismo para poder editarlo.
     */
    private function getFileLink($drive_service, $id_file) {
        $drive_file = $drive_service->files->get($id_file, array('fields' => 'webViewLink'));
        return $drive_file->getWebViewLink();
    }


    /**
     * Web Service que crea un documento en Google Drive para un trabajo.
     * Para crear un documento a un trabajo es necesario que ese trabajo esté aprobado y que no posea
     * un documento asociado ya.
     *
     * @Route("/crear_archivo/{id}", name="crear_archivo")
     */
    public function crearArchivoDeTrabajoAction (Request $request, Work $aWork) {
        //Se debe crear el documento solo si el trabajo esta aprobado y no posee un documento creado ya.
        if (!$aWork->getDocumentId() && $aWork->getState() == 'APPROVED' ) {
            $client = $this->getClient();          
        
            $this->loadToken($client);
        
            $drive_service = new \Google_Service_Drive($client);
            $createdFile = $this->createFile($drive_service, $aWork);

            $aWork->setDocumentId( $createdFile->getId() );
            $em = $this->getDoctrine()->getManager();

            $em->persist($aWork);
            $em->flush();

            $link_to_file = $this->getFileLink($drive_service, $createdFile->getId());
            $link_to_end_edit = $this->generateUrl('end_edit',array('id' => $aWork->getId() ),UrlGeneratorInterface::ABSOLUTE_URL);
        
            $responseData = array('file_link' => $link_to_file, 'link_to_end_edit' => $link_to_end_edit);
        }
        else {
            $responseData = array('error' => 'This Work has been Rejected or Already has a Document');
        }       
        
        $response = new JsonResponse();
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES);
        $response->setData($responseData);
        return $response;
       
      
    }

    /**
     * Determina la finalización de la edición del documento enviado por parámetro en la URL.
     * Para terminar el documento de un trabajo es necesario que previamente tenga asociado un documento
     * para lo cual debió ser aprobado antes.  
     *
     * @Route("/end_edit/{id}", name="end_edit")
     */
    public function endEditAction (Request $request, Work $aWork) {
        $client = $this->getClient();
        $this->loadToken($client);
        $drive_service = new \Google_Service_Drive($client);

        if ($aWork->getDocumentId() && $aWork->getState() == 'APPROVED' ) {
            $permissions = $drive_service->permissions->listPermissions($aWork->getDocumentId(),array('fields' => 'permissions'))->getPermissions();
            foreach ($permissions as $permission) {
                if ($permission->getRole() == 'writer') {
                    $drive_service->permissions->delete($aWork->getDocumentId(),$permission->getId() );
                } 
            }

            $aWork->setDocumentFinished(true);
            $em = $this->getDoctrine()->getManager();
            $em->persist($aWork);
            $em->flush();
        }
        return $this->render('work/end_edit.html.twig',array('work' => $aWork));
    }


    /**
     * Genera el documento que representa el Libro del Congreso. 
     * Para generar el documento es necesario que todos los trabajos que han sido aprobados tengan terminado
     * su documento y que además ya hayan sido programados para ser expuestos.
     *
     * @Route("/build_summary", name="build_summary")
     */
    public function buildSummaryAction (Request $request) {
        $aClient = $this->getClient();
        $this->loadToken($aClient);

        $worksRepository = $this->getDoctrine()->getRepository('AppBundle:Work');

        $worksApprovedNotFinished = $worksRepository->findBy( array('documentFinished' => false, 'state' => 'APPROVED') );
        $response = new JsonResponse();
        if ($worksApprovedNotFinished) {
            $response->setData( array('error' => 'There are works not finished') );
        }
        else {
            $worksApproved = $worksRepository->findAllApprovedByNumber();
            $pageNumber = 1;
            $summaryIndex = "TRABAJOS DEL CONGRESO DE TECNOLOGÍA E INFORMÁTICA CLOUD & BPM 2016 \n\n\n";
            foreach ($worksApproved as $aWork) {
                $summaryIndex = $summaryIndex.$aWork->getAuthor().", ".$aWork->getTitle().', '.
                $aWork->getExposition()->getExpositionDate()->format('d/m/Y H:i')." hs, ".$aWork->getExposition()->getSite().
                ".\t Página ".$pageNumber."\n\n";
                $pageNumber++;
            }
            $aService = new \Google_Service_Drive($aClient);
            $driveFile = new \Google_Service_Drive_DriveFile();
            $driveFile->setName('libroCongreso.doc');
            $driveFile->setMimeType('application/vnd.google-apps.document');
            $createdFile = $aService->files->create($driveFile, array('data' => $summaryIndex,'mimeType' => 'application/vnd.google-apps.document'));
            
            $exportFileLink = $this->generateUrl('export_file',array('id' => $createdFile->getId()),UrlGeneratorInterface::ABSOLUTE_URL);
            $response->setData(array('book_link' => $exportFileLink));

        }
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES);
        return $response; 

    } 

    /**
     * Web Service que asigna a un trabajo una fecha, hora y lugar de exposición. Para esto ya se dispone en la base de datos
     * un conjunto de posibles horarios y lugares que pueden ser asignados. Cada fecha, hora y lugar disponible, puede ser asignado
     * únicamente a un trabajo.
     * Es necesario que un trabajo tenga su documento terminado para ser programado.
     * 
     * @Route("/schedule_work/{id}", name="schedule_work")
     */
    public function scheduleWorkAction (Request $request, Work $aWork){
        $response = new JsonResponse();
        $responseData="";
        if (!$aWork->getExposition() && $aWork->getDocumentFinished()) {
            $em = $this->getDoctrine()->getManager();
            $expo = $em->getRepository('AppBundle:Exposition')->findOneByAvailable(true);
            $aWork->setExposition( $expo );
            $expo->setAvailable(false);
            $em->flush();   
            $responseData = array('site' => $expo->getSite(), 'exposition_date' => 'El '.$expo->getExpositionDate()->format('d/m/Y').' a las '.$expo->getExpositionDate()->format('H:i').' hs.' );
        }
        else {
            $responseData = array ('error' => 'The Work Already has an Exposition Assigned');
        }
        $response->setData($responseData); 
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES);     
        return $response;
    }


    /**
     * Exporta a formato PDF el archivo que tiene como ID el enviado por parámetro. 
     * Este Web Service, se utiliza para generar el Libro del Congreso.  
     * @Route("/export_file/{id}", name= "export_file")  
     */
    public function exportFileAction (Request $request, $id) {
        $client = $this->getClient();
        $this->loadToken($client);

        $driveService = new \Google_Service_Drive($client);

        $response = $driveService->files->export($id, 'application/pdf', array('alt' => 'media'));

        $fileContent = $response->getBody()->getContents();

        return new Response($fileContent,200, array('Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment;filename="libroCongreso.pdf"'));
    }

    /**
     * NO SE DEBE UTILIZAR
     * Solo es utilizado para generar un nuevo token de acceso a la API de Google Drive cuando hay conflictos
     * con el token existente.
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
                file_put_contents(REFRESH_TOKEN_PATH, $accessToken["refresh_token"]); 
            }
        }

        return new Response('Token Creado', Response::HTTP_OK, array('content-type' => 'text/html'));
    }
	

}
