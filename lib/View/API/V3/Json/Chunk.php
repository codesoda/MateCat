<?php
/**
 * Created by PhpStorm.
 * User: vincenzoruffa
 * Date: 13/09/2018
 * Time: 16:16
 */

namespace API\V3\Json;

use API\App\Json\OutsourceConfirmation;
use API\V2\Json\JobTranslator;
use API\V2\Json\ProjectUrls;
use CatUtils;
use Chunks_ChunkStruct;
use Constants;
use DataAccess\ShapelessConcreteStruct;
use Features\ReviewExtended\Model\QualityReportDao;
use Features\SecondPassReview;
use FeatureSet;
use Langs_LanguageDomains;
use Langs_Languages;
use LQA\ChunkReviewDao;
use Projects_ProjectStruct;
use RevisionFactory;
use Utils;
use WordCount_Struct;

class Chunk extends \API\V2\Json\Chunk {

    /**
     * @param \Chunks_ChunkStruct $chunk
     *
     * @return array
     * @throws \Exception
     * @throws \Exceptions\NotFoundException
     */
    public function renderOne( \Chunks_ChunkStruct $chunk ) {
        $project = $chunk->getProject();
        $featureSet = $project->getFeatures();
        return [
                'job' => [
                        'id'     => (int) $chunk->id,
                        'chunks' => [ $this->renderItem( $chunk, $project, $featureSet ) ]
                ]
        ];
    }

    /**
     * @param                         $jStruct Chunks_ChunkStruct
     *
     * @param Projects_ProjectStruct $project
     * @param FeatureSet              $featureSet
     *
     * @return array
     * @throws \Exception
     */
    public function renderItem( Chunks_ChunkStruct $jStruct, Projects_ProjectStruct $project, FeatureSet $featureSet ) {

        $outsourceInfo = $jStruct->getOutsource();
        $tStruct       = $jStruct->getTranslator();
        $outsource     = null;
        $translator    = null;
        if ( !empty( $outsourceInfo ) ) {
            $outsource = ( new OutsourceConfirmation( $outsourceInfo ) )->render();
        } else {
            $translator = ( !empty( $tStruct ) ? ( new JobTranslator() )->renderItem( $tStruct ) : null );
        }

        $jobStats = new WordCount_Struct();
        $jobStats->setIdJob( $jStruct->id );
        $jobStats->setDraftWords( $jStruct->draft_words + $jStruct->new_words ); // (draft_words + new_words) AS DRAFT
        $jobStats->setRejectedWords( $jStruct->rejected_words );
        $jobStats->setTranslatedWords( $jStruct->translated_words );
        $jobStats->setApprovedWords( $jStruct->approved_words );

        $lang_handler = Langs_Languages::getInstance();

        $subject_handler = Langs_LanguageDomains::getInstance();
        $subjects        = $subject_handler->getEnabledDomains();

        $subjects_keys = Utils::array_column( $subjects, "key" );
        $subject_key   = array_search( $jStruct->subject, $subjects_keys );

        $warningsCount = $jStruct->getWarningsCount();



        $result = [
                'id'                      => (int)$jStruct->id,
                'password'                => $jStruct->password,
                'source'                  => $jStruct->source,
                'target'                  => $jStruct->target,
                'sourceTxt'               => $lang_handler->getLocalizedName( $jStruct->source ),
                'targetTxt'               => $lang_handler->getLocalizedName( $jStruct->target ),
                'status'                  => $jStruct->status_owner,
                'subject'                 => $jStruct->subject,
                'subject_printable'       => $subjects[ $subject_key ][ 'display' ],
                'owner'                   => $jStruct->owner,
                'total_time_to_edit'      => $jStruct->total_time_to_edit,
                'avg_post_editing_effort' => $jStruct->avg_post_editing_effort,
                'open_threads_count'      => (int)$jStruct->getOpenThreadsCount(),
                'created_at'              => Utils::api_timestamp( $jStruct->create_date ),
                'pee'                     => $jStruct->getPeeForTranslatedSegments(),
                'private_tm_key'          => $this->getKeyList( $jStruct ),
                'warnings_count'          => $warningsCount->warnings_count,
                'warning_segments'        => ( isset( $warningsCount->warning_segments ) ? $warningsCount->warning_segments : [] ),
                'stats'                   => $this->_getStats( $jobStats ) ,
                'outsource'               => $outsource,
                'translator'              => $translator,
                'total_raw_wc'            => (int) $jStruct->total_raw_wc
        ];


        if ( $featureSet->hasRevisionFeature() ) {
            $chunkReviews = (new ChunkReviewDao() )->findAllChunkReviewsByChunkIds( [ [ $jStruct->id, $jStruct->password ] ] );

            foreach( $chunkReviews as $index => $chunkReview ) {
                list( $passfail, $reviseIssues, $quality_overall, $score, $total_issues_weight, $total_reviewed_words_count, $categories ) =
                        $this->revisionQualityVars( $jStruct, $project, $chunkReview );

                $result = $this->populateQualitySummarySection($result, $chunkReview->source_page,
                        $jStruct, $quality_overall, $reviseIssues, $score, $categories,
                        $total_issues_weight, $total_reviewed_words_count, $passfail );
            }

        } else {
            $qualityInfoArray = CatUtils::getQualityInfoFromJobStruct( $jStruct, $featureSet );

            list( $passfail, $reviseIssues, $quality_overall, $score, $total_issues_weight, $total_reviewed_words_count, $categories ) =
                    $this->legacyRevisionQualityVars( $jStruct, $featureSet, $jobStats, $qualityInfoArray );

            $result = $this->populateQualitySummarySection($result, Constants::SOURCE_PAGE_REVISION,
                    $jStruct, $quality_overall, $reviseIssues, $score, $categories,
                    $total_issues_weight, $total_reviewed_words_count, $passfail );
        }

        /**
         * @var $projectData ShapelessConcreteStruct[]
         */
        $projectData = ( new \Projects_ProjectDao() )->setCacheTTL( 60 * 60 * 24 )->getProjectData( $project->id, $project->password );

        $formatted = new ProjectUrls( $projectData );

        /** @var $formatted ProjectUrls */
        $formatted = $featureSet->filter( 'projectUrls', $formatted );

        $urlsObject       = $formatted->render( true );
        $result[ 'urls' ] = $urlsObject[ 'jobs' ][ $jStruct->id ][ 'chunks' ][ $jStruct->password ];

        $result[ 'urls' ][ 'original_download_url' ]    = $urlsObject[ 'jobs' ][ $jStruct->id ][ 'original_download_url' ];
        $result[ 'urls' ][ 'translation_download_url' ] = $urlsObject[ 'jobs' ][ $jStruct->id ][ 'translation_download_url' ];
        $result[ 'urls' ][ 'xliff_download_url' ]       = $urlsObject[ 'jobs' ][ $jStruct->id ][ 'xliff_download_url' ];

        return $result;

    }

    /**
     * @param $result
     * @param $index
     * @param $jStruct
     * @param $quality_overall
     * @param $reviseIssues
     * @param $score
     * @param $categories
     * @param $total_issues_weight
     * @param $total_reviewed_words_count
     * @param $passfail
     *
     * @return mixed
     */
    protected function populateQualitySummarySection( $result, $source_page, $jStruct, $quality_overall, $reviseIssues, $score, $categories,
                                                         $total_issues_weight, $total_reviewed_words_count, $passfail ) {

        if ( !isset( $result['quality_summary'] ) ) {
            $result['quality_summary'] = [];
        }

        $result['quality_summary'][] = [
                'revision_number'     => SecondPassReview\Utils::sourcePageToRevisionNumber( $source_page ),
                'equivalent_class'    => $jStruct->getQualityInfo(),
                'quality_overall'     => $quality_overall,
                'errors_count'        => (int)$jStruct->getErrorsCount(),
                'revise_issues'       => $reviseIssues,
                'score'               => floatval($score),
                'categories'          => $categories,
                'total_issues_weight' => (int)$total_issues_weight,
                'total_reviewed_words_count' => (int)$total_reviewed_words_count,
                'passfail'            => $passfail,
        ];

        return $result ;
    }

    protected function _getStats( $jobStats ) {
        $stats = CatUtils::getPlainStatsForJobs( $jobStats );
        unset( $stats ['id'] );
        return array_change_key_case( $stats, CASE_LOWER );
    }

    /**
     * @param Chunks_ChunkStruct $jStruct
     * @param FeatureSet         $featureSet
     * @param                    $jobStats
     * @param                    $chunkReview
     *
     * @return array
     * @internal param $reviseIssues
     */
    protected function legacyRevisionQualityVars( Chunks_ChunkStruct $jStruct, FeatureSet $featureSet, WordCount_Struct $jobStats, $chunkReview ) {
        $reviseIssues = [];

        $reviseClass = new \Constants_Revise();

        $jobQA = new \Revise_JobQA(
                $jStruct->id,
                $jStruct->password,
                $jobStats->getTotal(),
                $reviseClass
        );

        list( $jobQA, $reviseClass ) = $featureSet->filter( "overrideReviseJobQA", [
                $jobQA, $reviseClass
        ], $jStruct->id,
                $jStruct->password,
                $jobStats->getTotal() );

        /**
         * @var $jobQA \Revise_JobQA
         */
        $jobQA->retrieveJobErrorTotals();
        $jobQA->evalJobVote();
        $qa_data = $jobQA->getQaData();

        foreach ( $qa_data as $issue ) {
            $reviseIssues[ "err_" . str_replace( " ", "_", strtolower( $issue[ 'field' ] ) ) ] = [
                    'allowed' => $issue[ 'allowed' ],
                    'found'   => $issue[ 'found' ],
                    'founds'  => $issue[ 'founds' ],
                    'vote'    => $issue[ 'vote' ]
            ];
        }

        $quality_overall = strtolower( $chunkReview[ 'minText' ] );

        $score = 0;

        $total_issues_weight = 0;
        $total_reviewed_words_count = 0;

        $categories = CatUtils::getSerializedCategories( $reviseClass );
        $passfail = ''  ;

        return array(
                $passfail,
                $reviseIssues, $quality_overall, $score, $total_issues_weight, $total_reviewed_words_count, $categories
        );
    }

    /**
     * @param Chunks_ChunkStruct     $jStruct
     * @param Projects_ProjectStruct $project
     * @param                        $chunkReview
     *
     * @return array
     * @internal param $reviseIssues
     */
    protected function revisionQualityVars( Chunks_ChunkStruct $jStruct, Projects_ProjectStruct $project, $chunkReview ) {
        $reviseIssues = [];

        $qualityReportDao = new QualityReportDao();
        $qa_data          = $qualityReportDao->getReviseIssuesByChunk( $jStruct->id, $jStruct->password, $chunkReview->source_page );
        foreach ( $qa_data as $issue ) {
            if ( !isset( $reviseIssues[ $issue->id_category ] ) ) {
                $reviseIssues[ $issue->id_category ] = [
                        'name'   => $issue->issue_category_label,
                        'founds' => [
                                $issue->issue_severity => 1
                        ]
                ];
            } else {
                if ( !isset( $reviseIssues[ $issue->id_category ][ 'founds' ][ $issue->issue_severity ] ) ) {
                    $reviseIssues[ $issue->id_category ][ 'founds' ][ $issue->issue_severity ] = 1;
                } else {
                    $reviseIssues[ $issue->id_category ][ 'founds' ][ $issue->issue_severity ]++;
                }
            }
        }

        if ( @$chunkReview->is_pass == null ) {
            $quality_overall = $chunkReview->is_pass;
        } elseif ( !empty( $chunkReview->is_pass ) ) {
            $quality_overall = 'excellent';
        } else {
            $quality_overall = 'fail';
        }

        $chunkReviewModel = RevisionFactory::getInstance()->getChunkReviewModel( $chunkReview );

        $score = number_format( $chunkReviewModel->getScore(), 2, ".", "" );

        $total_issues_weight        = $chunkReviewModel->getPenaltyPoints();
        $total_reviewed_words_count = $chunkReviewModel->getReviewedWordsCount();

        $model      = $project->getLqaModel();
        $categories = $model->getCategoriesAndSeverities();
        $passfail = [ 'type' => $model->pass_type, 'options' => [ 'limit' => $chunkReviewModel->getQALimit() ] ] ;

        return array(
                $passfail,
                $reviseIssues, $quality_overall, $score, $total_issues_weight, $total_reviewed_words_count, $categories
        );
    }

}