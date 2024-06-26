<?php

namespace Drupal\os2web_meetings_acadre\Plugin\migrate\source;

use Drupal\Component\FileSystem\RegexDirectoryIterator;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\node\Entity\Node;
use Drupal\os2web_meetings\Entity\Meeting;
use Drupal\os2web_meetings\Form\SettingsForm;
use Drupal\os2web_meetings\Plugin\migrate\source\MeetingsDirectory;
use Drupal\migrate\Row;

/**
 * Source plugin for retrieving data via URLs.
 *
 * @MigrateSource(
 *   id = "os2web_meetings_directory_acadre"
 * )
 */
class MeetingsDirectoryAcadre extends MeetingsDirectory {

  protected $agendaExtraData;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration) {
    $urls = $this->collectAgendaUrls($configuration);
    $configuration['urls'] = $urls;

    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
  }

  /**
   * {@inheritdoc}
   */
  public function collectAgendaUrls($configuration) {
    $manifestPaths = [];
    $agendaPaths = [];

    // Traverse through the directory (not recursively)
    $path = $this->getMeetingsManifestPath();
    $iterator = new RegexDirectoryIterator($path, $configuration['pattern']);
    foreach ($iterator as $fileinfo) {
      $manifestPaths[] = $fileinfo->getPathname();
    }

    // Loading agenda XML's.
    foreach ($manifestPaths as $manifest) {
      $manifalestRealPath = \Drupal::service('file_system')->realpath($manifest);

      libxml_clear_errors();
      $manifestXml = simplexml_load_file($manifalestRealPath);
      foreach (libxml_get_errors() as $error) {
        $error_string = self::parseLibXmlError($error);
        \Drupal::logger('os2web_meetings')->error('URL skipped. XML invalid syntax: ' . $error_string);
        return FALSE;
      }

      $agendas = $manifestXml->xpath("//table[@name='producedAgenda']/fields");
      if (empty($agendas)) {
        \Drupal::logger('os2web_meetings')->error('Empty list of import items in !file', array('!file' => $manifest));
      }

      foreach ($agendas as $agenda) {
        $isPublish = $agenda->xpath("field/@publish");
        $isPublish = (string) array_shift($isPublish);

        if (filter_var($isPublish, FILTER_VALIDATE_BOOLEAN)) {
          // Filesfolder - stored as "Files_1234_1234567".
          $filesfolder = $this->readValueFromSimpleXmlElement($agenda, "field/@filesfolder");

          // XML filename - stored as "1234567.xml".
          $xmlfilename = $this->readValueFromSimpleXmlElement($agenda, "field/@xmlfilename");
          $xmlfilename = $this->capitalizeExtension($xmlfilename);

          // Doc filename - stores as '1234567.docx'.
          $docfilename = $this->readValueFromSimpleXmlElement($agenda, "field/@docfilename");
          $docfilename = $this->capitalizeExtension($docfilename);

          // Type - stored as 0, 1 or 2.
          $type = (int) $this->readValueFromSimpleXmlElement($agenda, "field/@type");

          // Access - stored as 1 or 2.
          $access = (int) $this->readValueFromSimpleXmlElement($agenda, "field/@accessid");

          $manifestPath = $this->getMeetingsManifestPath() . '/' . $filesfolder . '/' . $xmlfilename;

          // Only adding if file exists.
          if (file_exists($manifestPath)) {
            $agendaPaths[] = $manifestPath;

            // Saving extra information for manifest.
            $this->agendaExtraData[$manifestPath] = [
              'agenda_type' => $type,
              'agenda_access' => $access,
              'agenda_document_filename' => $docfilename
            ];
          }
        }
      }
    }

    return $agendaPaths;
  }

  /**
   * {@inheritDoc}
   */
  public function prepareRow(Row $row) {
    $source = $row->getSource();

    // Altering the title.
    $startDateTs = $this->convertStartDateToCanonical($source);
    $starDate = new \DateTime();
    $starDate->setTimestamp($startDateTs);
    $title = $source['committee_name'] . ' ' . $starDate->format('d-m-Y');
    $row->setSourceProperty('title', $title);

    $manifestPath = $source['manifest_path'];
    $agendaExtraData = $this->agendaExtraData[$manifestPath];

    // Adding extra information.
    foreach ($agendaExtraData as $extraDataKey => $extraDateValue) {
      $row->setSourceProperty($extraDataKey, $extraDateValue);
    }

    return parent::prepareRow($row);
  }

  /**
   * {@inheritdoc}
   */
  public function getMeetingsManifestPath() {
    return \Drupal::config(SettingsForm::$configName)
      ->get('acadre_meetings_manifest_path');
  }

  /**
   * {@inheritdoc}
   */
  public function convertAgendaIdToCanonical(array $source) {
    return $source['agenda_id'];
  }

  /**
   * {@inheritdoc}
   */
  public function convertAgendaAccessToCanonical(array $source) {
    return ($source['agenda_access'] == 1) ? TRUE : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function convertAgendaTypeToCanonical(array $source) {
    if ($source['agenda_type'] == 1) {
      return MeetingsDirectory::AGENDA_TYPE_DAGSORDEN;
    }
    elseif ($source['agenda_type'] == 2) {
      return MeetingsDirectory::AGENDA_TYPE_REFERAT;
    }
    else {
      return MeetingsDirectory::AGENDA_TYPE_KLADDE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function convertStartDateToCanonical(array $source) {
    $start_date = $source['meeting_start_date'];

    return $this->convertDateToTimestamp($start_date);
  }

  /**
   * {@inheritdoc}
   */
  public function convertEndDateToCanonical(array $source) {
    $end_date = $source['meeting_end_date'];

    return $this->convertDateToTimestamp($end_date);
  }

  /**
   * {@inheritdoc}
   */
  public function convertAgendaDocumentToCanonical(array $source) {
    return [
      'uri' => $source['agenda_document_filename'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function convertCommitteeToCanonical(array $source) {
    $id = $source['committee_id'];
    $name = $source['committee_name'];
    return [
      'id' => $id,
      'name' => $name,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function convertLocationToCanonical(array $source) {
    $id = $source['location_name'];
    $name = $source['location_name'];
    return [
      'id' => $id,
      'name' => $name,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function convertBulletPointsToCanonical(array $source) {
    $canonical_bullet_points = [];

    // Not using the bullet points stored in the source because due to SimpleXML
    // to array conversion attributes are lost.
    // Instead making extra xpath query to have the full data.
    $manifestXml = simplexml_load_file($source['manifest_path']);
    $source_bullet_points = $manifestXml->xpath("//table[@name='agendaitemparents']");

    foreach ($source_bullet_points as $bullet_point) {
      // Getting title.
      $title = $this->readValueFromSimpleXmlElement($bullet_point, "table[@name='agendaitem']/fields/field[@name='name']");

      // Skipping bullet points with no titles.
      if (!$title) {
        continue;
      }

      // Getting fields.
      $id = $this->readValueFromSimpleXmlElement($bullet_point, "table[@name='agendaitem']/fields/field[@name='sysid']");
      $bpNumber = $this->readValueFromSimpleXmlElement($bullet_point, "fields/field[@name='sort']");
      $publishingType = (int) $this->readValueFromSimpleXmlElement($bullet_point, "table[@name='agendaitem']/fields/field[@name='access']");
      $access = ($publishingType === 1) ? TRUE : FALSE;
      $caseno = $this->readValueFromSimpleXmlElement($bullet_point, "table[@name='agendaitem']/fields/field[@name='caseno']");
      $comname = $this->readValueFromSimpleXmlElement($bullet_point, "table[@name='agendaitem']/fields/field[@name='comname']");

      // Getting attachments (text).
      $source_attachments = $bullet_point->xpath("table[@name='agendaitem']/table[@name='bullet']");
      $canonical_attachments = [];
      if (is_array($source_attachments)) {
        $canonical_attachments = $this->convertAttachmentsToCanonical($source_attachments);
      }

      // Getting enclosures (files).
      $source_enclosures = $bullet_point->xpath("table[@name='agendaitem']/table[@name='enclosure']");
      $canonical_enclosures = [];
      if (is_array($source_enclosures)) {
        $canonical_enclosures = $this->convertEnclosuresToCanonical($source_enclosures);
      }

      $canonical_bullet_points[] = [
        'id' => $id,
        'number' => $bpNumber,
        'title' => $title,
        'access' => $access,
        'case_nr' => $caseno,
        'com_name' => $comname,
        'attachments' => $canonical_attachments,
        'enclosures' =>  $canonical_enclosures,
      ];
    }

    usort($canonical_bullet_points, function ($item1, $item2) {
    if ($item1['number'] == $item2['number']) return 0;
      return $item1['number'] < $item2['number'] ? -1 : 1;
    });

    return $canonical_bullet_points;
  }

  /**
   * {@inheritdoc}
   */
  public function convertAttachmentsToCanonical(array $source_attachments, $access = TRUE) {
    $canonical_attachments = [];

    foreach ($source_attachments as $source_attachment) {
      $content = $this->readValueFromSimpleXmlElement($source_attachment, "fields/field[@name='bulletcontent']");

      if (!empty($content)) {
        $id = $this->readValueFromSimpleXmlElement($source_attachment, "fields/field[@name='sysid']");
        $title = $this->readValueFromSimpleXmlElement($source_attachment, "fields/field[@name='bulletname']");

        $canonical_attachments[] = [
          'id' => $id,
          'title' => $title,
          'body' => $content,
          'access' => TRUE,
        ];
      }
    }

    return $canonical_attachments;
  }

  /**
   * {@inheritdoc}
   */
  public function convertEnclosuresToCanonical(array $source_enclosures) {
    $canonical_enclosures = [];

    foreach ($source_enclosures as $enclosure) {
      $id = $this->readValueFromSimpleXmlElement($enclosure, "fields/field[@name='sysid']");
      $title = $this->readValueFromSimpleXmlElement($enclosure, "fields/field[@name='name']");
      $uri = $this->readValueFromSimpleXmlElement($enclosure, "fields/field[@name='filename']");

      $access = (int) $this->readValueFromSimpleXmlElement($enclosure, "fields/field[@name='access']");
      $access = ($access === 1) ? TRUE : FALSE;

      $canonical_enclosures[] = [
        'id' => $id,
        'title' => $title,
        'uri' => $uri,
        'access' => $access,
      ];
    }

    return $canonical_enclosures;
  }

  /**
   * Converts Danish specific string date into timestamp in UTC.
   *
   * @param string $dateStr
   *   Date as string.
   *
   * @return int
   *   Timestamp in UTC.
   *
   * @throws \Exception
   */
  private function convertDateToTimestamp($dateStr) {
    $dateTime = new \DateTime($dateStr, new \DateTimeZone('Europe/Copenhagen'));

    return $dateTime->getTimestamp();
  }
  /**
   * {@inheritdoc}
   */
  public function convertParticipantToCanonical(array $source) {
    return '';
  }

  /**
   * Replacing backslash with normal slash.
   */

  /**
   * Replacing backslash with normal slash.
   *
   * @param $path
   *   Path string
   *
   * @return string|string[]
   *   String with the backslashes being inverted.
   */
  private function invertPathsBackslashes($path) {
    return str_replace('\\', '/', $path);
  }

  /**
   * Capitalising the extension.
   *
   * @param $path
   *   Path string
   *
   * @return string|string[]
   *   URI with file extension being capitalized.
   */
  private function capitalizeExtension($path) {
    $path_parts = pathinfo($path);

    $extension = $path_parts['extension'];
    $capExtension = strtoupper($extension);

    // Avoiding wrong replacements by adding dot.
    return str_replace(".$extension", ".$capExtension", $path);
  }

  /**
   * Reads the value from SimpleXMLElement.
   *
   * Helper function to avoid  simplify the value extraction.
   *
   * @param \SimpleXMLElement $element
   *   The element to read value from.
   * @param $selector
   *   XML path selector.
   *
   * @return string
   *   Returned value.
   */
  private function readValueFromSimpleXmlElement(\SimpleXMLElement $element, $selector) {
    $value = $element->xpath($selector);
    return (string) array_shift($value);
  }

  /**
   * {@inheritdoc}
   */
  public function postImport(MigrateImportEvent $event) {
    parent::postImport($event);

    // Find all meetings.
    $query = \Drupal::entityQuery('node')->accessCheck(false);
    $query->condition('type', 'os2web_meetings_meeting');
    $query->condition('field_os2web_m_source', $this->getPluginId());
    $entity_ids = $query->execute();

    $meetings = Node::loadMultiple($entity_ids);

    // Group meetings as:
    // $groupedMeetings[<meeting_id>][<agenda_id>] = <node_id> .
    $groupedMeetings = [];
    foreach ($meetings as $meeting) {
      $os2webMeeting = new Meeting($meeting);

      $meeting_id = $os2webMeeting->getMeetingId();
      $agenda_id = $os2webMeeting->getEsdhId();

      $groupedMeetings[$meeting_id][$agenda_id] = $os2webMeeting->id();

      // Sorting agendas, so that lowest agenda ID is always the first.
      ksort($groupedMeetings[$meeting_id]);
    }

    // Process grouped meetings and set addendum fields.
    foreach ($groupedMeetings as $meeting_id => $agendas) {
      // Skipping if agenda count is 1.
      if (count($agendas) == 1) {
        continue;
      }

      $mainAgendaNodedId = array_shift($agendas);

      foreach ($agendas as $agenda_id => $node_id) {
        // Getting the meeting.
        $os2webMeeting = new Meeting($meetings[$node_id]);

        // Setting addendum field, meeting is saved inside a function.
        $os2webMeeting->setAddendum($mainAgendaNodedId);
      }
    }
  }
}
