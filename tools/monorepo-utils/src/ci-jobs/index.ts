/**
 * External dependencies
 */
import { Command } from '@commander-js/extra-typings';
import { setOutput } from '@actions/core';

/**
 * Internal dependencies
 */
import { Logger } from '../core/logger';
import { buildProjectGraph } from './lib/project-graph';
import { getFileChanges } from './lib/file-changes';
import { createJobsForChanges } from './lib/job-processing';
import { isGithubCI } from '../core/environment';
import { testTypes } from './lib/config';

const program = new Command( 'ci-jobs' )
	.description(
		'Generates CI workflow jobs based on the changes since the base ref.'
	)
	.option(
		'-r --base-ref <baseRef>',
		'Base ref to compare the current ref against for change detection. If not specified, all projects will be considered changed.',
		''
	)
	.option(
		'-e --event <event>',
		'Github event for which to run the jobs. If not specified, all events will be considered.',
		''
	)
	.action( async ( options ) => {
		Logger.startTask( 'Parsing Project Graph', true );
		const projectGraph = buildProjectGraph();
		Logger.endTask( true );

		if ( options.event === '' ) {
			Logger.warn( 'No event was specified, considering all projects.' );
		} else {
			Logger.warn(
				`Only projects configured for '${ options.event }' event will be considered.`
			);
		}

		let fileChanges;
		if ( options.baseRef === '' ) {
			Logger.warn(
				'No base ref was specified, forcing all projects to be marked as changed.'
			);
			fileChanges = true;
		} else {
			Logger.startTask( 'Pulling File Changes', true );
			fileChanges = getFileChanges( projectGraph, options.baseRef );
			Logger.endTask( true );
		}

		Logger.startTask( 'Creating Jobs', true );
		const jobs = await createJobsForChanges( projectGraph, fileChanges, {
			commandVars: {
				baseRef: options.baseRef,
				event: options.event,
			},
		} );
		Logger.endTask( true );

		if ( isGithubCI() ) {
			setOutput( 'lint-jobs', JSON.stringify( jobs.lint ) );

			testTypes.forEach( ( type ) => {
				setOutput(
					`${ type }-test-jobs`,
					JSON.stringify( jobs[ `${ type }Test` ] )
				);
			} );
			return;
		}

		if ( jobs.lint.length > 0 ) {
			Logger.notice( 'Lint Jobs' );
			for ( const job of jobs.lint ) {
				const optional = job.optional ? '(optional)' : '';
				Logger.notice(
					`-  ${ job.projectName } - ${ job.command }${ optional }`
				);
			}
		} else {
			Logger.notice( 'No lint jobs to run.' );
		}

		testTypes.forEach( ( type ) => {
			if ( jobs[ `${ type }Test` ].length > 0 ) {
				Logger.notice( `${ type } test Jobs` );
				for ( const job of jobs[ `${ type }Test` ] ) {
					const optional = job.optional ? ' (optional)' : '';
					Logger.notice(
						`-  ${ job.projectName } - ${ job.name }${ optional }`
					);
				}
			} else {
				Logger.notice( `No ${ type } test jobs to run.` );
			}
		} );
	} );

export default program;
