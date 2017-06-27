<?php

Route::post('deploy', 'CrisPossa\GitDeploy\Http\GitDeployController@gitHook');
