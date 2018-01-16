#! /bin/bash

DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
DMP_FILE=$1
SYMBOLS_DIR=$2
WORKING_DIR="/tmp/"
STATIC_SYMBOLS_DIR="${DIR}/static-symbols/"
BLACKLIST="nv.* ig9.* dropbox.* Wacom.* wintab32.*"

if [ ! -e "${DMP_FILE}" ]
then
	echo "${DMP_FILE} does not exist"
	exit 1
fi

if [ ! -d "${SYMBOLS_DIR}" ]
then
	echo "${SYMBOLS_DIR} does not exist or is not a directory"
	exit 1
fi

if [ ! -d "${STATIC_SYMBOLS_DIR}" ]
then
	echo "${STATIC_SYMBOLS_DIR} does not exist or is not a directory"
	exit 1
fi

STACKWALK="${DIR}/breakpad/src/processor/minidump_stackwalk"
DUMPSYMS="${DIR}/breakpad/src/tools/windows/binaries/dump_syms.exe"

MISSING_SYMBOLS=$(${STACKWALK} ${DMP_FILE} ${SYMBOLS_DIR} 2> /dev/null | \
	grep "WARNING: No symbols" | \
	sed -e 's/.*WARNING: No symbols, \([^,]*\), \([^)]*\))/\1 \2/' | \
	sort | uniq)

dumpSyms()
{
	wine ${DUMPSYMS} "${1}" 2> /dev/null > ${2}

	if [[ -s ${2} ]]
	then
		chmod 664 ${2}
		return 0
	else
		rm -f ${2}
		return 1
	fi
}

echo "Fetching symbols..."
while read -r LINE
do
	ARRAY=($LINE)
	PDB=${ARRAY[0]}
	ID=${ARRAY[1]}

	if [[ -z "${ID}" || -z "${PDB}" ]]
	then
		continue
	fi

	LIB="${PDB%.*}"
	SYM_FILE="${SYMBOLS_DIR}/${PDB}/${ID}/${LIB}.sym"
	
	if [ -e ${SYM_FILE} ]
	then
		echo "WARNING: ${SYM_FILE} already exists, not overwriting"
		continue
	fi

	echo -n ${PDB}
	STATIC_PDBS=$(find ${STATIC_SYMBOLS_DIR} -name ${PDB})
	if [[ ! -z "${STATIC_PDBS}" ]]
	then
		echo "."
		SYM_DIR=$(dirname ${SYM_FILE})
		mkdir -m 775 -p ${SYM_DIR}

		for STATIC_PDB in ${STATIC_PDBS}
		do
			TEMP_SYM_FILE=$(mktemp)
			dumpSyms ${STATIC_PDB} ${TEMP_SYM_FILE}

			if [ $? == 0 ]
			then
				SYM_ID=$(head -n1 ${TEMP_SYM_FILE} | cut -d' ' -f4)
				if [ "${SYM_ID}" != "${ID}" ]
				then
					echo "  ID doesn't match: ${STATIC_PDB} expected ${ID} seen ${SYM_ID}"
					rm -f ${TEMP_SYM_FILE}
				else
					mv ${TEMP_SYM_FILE} ${SYM_FILE}
					echo "  static converted: ${SYM_FILE}"
				fi
			fi
		done
	else
		CAB_FILE="${LIB}.pd_"
		CAB_URL="http://msdl.microsoft.com/download/symbols/${PDB}/${ID}/${CAB_FILE}"
		echo -n "."

		BLACK_LISTED=0
		for RE in ${BLACKLIST}
		do
			if [[ ${PDB} =~ ${RE} ]]
			then
				echo " blacklisted"
				BLACK_LISTED=1
			fi
		done

		if [ ${BLACK_LISTED} == "1" ]
		then
			continue
		fi

		curl -s -f -A "Microsoft-Symbol-Server/6.3.0.0" \
			"${CAB_URL}" -o "${WORKING_DIR}/${CAB_FILE}"
		if [ -e "${WORKING_DIR}/${CAB_FILE}" ]
		then
			EXTRACTED_FILES=$(cabextract -d ${WORKING_DIR} "${WORKING_DIR}/${CAB_FILE}" | \
				grep "  extracting" | sed -e 's/\s*extracting\s*//')
		else
			PDB_FILE="${LIB}.pdb"
			PDB_URL="http://msdl.microsoft.com/download/symbols/${PDB}/${ID}/${PDB_FILE}"
			echo -n "."
			curl -s -f -A "Microsoft-Symbol-Server/6.3.0.0" \
				"${PDB_URL}" -o "${WORKING_DIR}/${PDB_FILE}"

			if [ ! -e "${WORKING_DIR}/${PDB_FILE}" ]
			then
				echo " failed to download"
				continue
			fi

			EXTRACTED_FILES="${WORKING_DIR}/${PDB_FILE}"
		fi

		echo "."
		while read -r EXTRACTED_FILE
		do
			SYM_DIR=$(dirname ${SYM_FILE})
			mkdir -m 775 -p ${SYM_DIR}

			EXT="${EXTRACTED_FILE##*.}"
			case ${EXT} in
			"pdb")
				dumpSyms ${EXTRACTED_FILE} ${SYM_FILE}
				if [ $? == 0 ]
				then
					echo "  converted: ${SYM_FILE}"
				fi
				;;
			"sym")
				cp "${EXTRACTED_FILE}" ${SYM_FILE}
				chmod 664 ${SYM_FILE}
				echo "  copied: ${SYM_FILE}"
				;;
			esac
			rm ${EXTRACTED_FILE}
		done <<< "${EXTRACTED_FILES}"

		if [ -e "${WORKING_DIR}/${CAB_FILE}" ]
		then
			rm "${WORKING_DIR}/${CAB_FILE}"
		fi

		if [ -e "${WORKING_DIR}/${PDB_FILE}" ]
		then
			rm "${WORKING_DIR}/${PDB_FILE}"
		fi
	fi
done <<< "${MISSING_SYMBOLS}"

EMPTY_SYM_FILES=$(find symbols -size 0)
if [[ ! -z "${EMPTY_SYM_FILES}" ]]
then
	echo "Deleting empty .sym files:"
	for EMPTY_SYM_FILE in ${EMPTY_SYM_FILES}
	do
		echo "  ${EMPTY_SYM_FILE}"
		rm ${EMPTY_SYM_FILE}
	done
fi
echo "...done"
